//! Peep desktop app: shows the viewer in a native window, runs the viewer server with the
//! bundled PHP when none is running, and adds a tray, a global hotkey, native
//! notifications, start at login, updates and the peep:// link.

mod server;
mod settings;
mod updates;

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use std::time::Duration;

use serde::Serialize;
use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::TrayIconBuilder;
use tauri::{
    AppHandle, Emitter, Manager, RunEvent, Url, WebviewUrl, WebviewWindow, WebviewWindowBuilder,
    WindowEvent, Wry,
};
use tauri_plugin_autostart::{MacosLauncher, ManagerExt as _};
use tauri_plugin_deep_link::DeepLinkExt;
use tauri_plugin_dialog::DialogExt;
use tauri_plugin_global_shortcut::{GlobalShortcutExt, ShortcutState};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;

const MAIN: &str = "main";
const TRAY: &str = "tray";

/// Shows and hides the window from anywhere. Cmd+Shift+P is left alone: editors use it.
const HOTKEY: &str = "CmdOrCtrl+Alt+P";

struct Viewer {
    port: u16,
    sidecar: Mutex<Option<server::Sidecar>>,
    pinned: AtomicBool,
    pin_item: Mutex<Option<CheckMenuItem<Wry>>>,
    update_item: Mutex<Option<MenuItem<Wry>>>,
    found_update: Mutex<Option<updates::Release>>,
}

impl Viewer {
    fn url(&self) -> String {
        format!("http://127.0.0.1:{}", self.port)
    }
}

#[derive(Clone, Serialize)]
struct StartupFailed {
    message: String,
    log: String,
}

/// PEEP_DESKTOP_TRACE=1 prints what the viewer page asks of the app (for troubleshooting).
fn trace(message: &str) {
    if std::env::var_os("PEEP_DESKTOP_TRACE").is_some() {
        eprintln!("peep-desktop: {message}");
    }
}

/// The unread count of the mail and SMS inboxes, from the viewer page: on the tray and the dock.
#[tauri::command]
fn set_unread(app: AppHandle, count: u32) {
    trace(&format!("set_unread({count})"));

    let label = (count > 0).then(|| count.to_string());

    if let Some(tray) = app.tray_by_id(TRAY) {
        #[cfg(target_os = "macos")]
        let _ = tray.set_title(label.as_deref());
        let _ = tray.set_tooltip(Some(match &label {
            Some(count) => format!("Peep: {count} unread"),
            None => "Peep".to_string(),
        }));
    }

    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.set_badge_count(label.map(|_| i64::from(count)));
    }
}

/// A native notification, shown only while the window is not in front.
#[tauri::command]
fn notify(app: AppHandle, title: String, body: String) {
    trace(&format!("notify({title})"));

    let focused = app
        .get_webview_window(MAIN)
        .and_then(|window| window.is_focused().ok())
        .unwrap_or(false);

    if !focused {
        let _ = app.notification().builder().title(title).body(body).show();
    }
}

#[tauri::command]
fn show_window(app: AppHandle) {
    trace("show_window");

    show_main(&app);
}

#[tauri::command]
fn hide_window(app: AppHandle) {
    trace("hide_window");

    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.hide();
    }
}

#[tauri::command]
fn set_pinned(app: AppHandle, pinned: bool) {
    pin(&app, pinned);
}

fn pin(app: &AppHandle, pinned: bool) {
    let viewer = app.state::<Viewer>();
    viewer.pinned.store(pinned, Ordering::Relaxed);

    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.set_always_on_top(pinned);
    }

    let item = viewer.pin_item.lock().ok().and_then(|item| item.clone());
    if let Some(item) = item {
        let _ = item.set_checked(pinned);
    }
}

fn show_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.unminimize();
        let _ = window.show();
        let _ = window.set_focus();
    }
}

fn toggle_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window(MAIN) {
        if window.is_visible().unwrap_or(false) && window.is_focused().unwrap_or(false) {
            let _ = window.hide();
        } else {
            show_main(app);
        }
    }
}

/// `peep://open?url=http://127.0.0.1:23517` (from `peep-server serve --open=window`):
/// show the window, on that viewer when it is a local one.
fn open_link(app: &AppHandle, link: &Url) {
    if link.scheme() != "peep" {
        return;
    }

    let target = link
        .query_pairs()
        .find(|(name, _)| name == "url")
        .and_then(|(_, value)| Url::parse(&value).ok())
        .filter(|url| {
            url.scheme() == "http"
                && matches!(url.host_str(), Some("127.0.0.1" | "localhost" | "[::1]"))
        });

    if let (Some(url), Some(window)) = (target, app.get_webview_window(MAIN)) {
        let _ = window.navigate(url);
    }

    show_main(app);
}

fn build_tray(app: &AppHandle) -> tauri::Result<()> {
    let show = MenuItem::with_id(app, "show", "Show Peep", true, Some(HOTKEY))?;
    let browser = MenuItem::with_id(app, "browser", "Open in browser", true, None::<&str>)?;
    let pinned = CheckMenuItem::with_id(app, "pin", "Always on top", true, false, None::<&str>)?;
    let login = CheckMenuItem::with_id(
        app,
        "login",
        "Start at login",
        true,
        app.autolaunch().is_enabled().unwrap_or(false),
        None::<&str>,
    )?;
    let update = MenuItem::with_id(app, "update", "Check for Updates...", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Quit Peep", true, Some("CmdOrCtrl+Q"))?;
    let menu = Menu::with_items(
        app,
        &[
            &show,
            &browser,
            &PredefinedMenuItem::separator(app)?,
            &pinned,
            &login,
            &update,
            &PredefinedMenuItem::separator(app)?,
            &quit,
        ],
    )?;

    if let Ok(mut item) = app.state::<Viewer>().pin_item.lock() {
        *item = Some(pinned);
    }

    if let Ok(mut item) = app.state::<Viewer>().update_item.lock() {
        *item = Some(update);
    }

    let login_item = login.clone();
    TrayIconBuilder::with_id(TRAY)
        .icon(tauri::image::Image::from_bytes(include_bytes!(
            "../icons/tray.png"
        ))?)
        .icon_as_template(true)
        .tooltip("Peep")
        .menu(&menu)
        .on_menu_event(move |app, event| match event.id().as_ref() {
            "show" => show_main(app),
            "browser" => {
                let url = app.state::<Viewer>().url();
                let _ = app.opener().open_url(url, None::<&str>);
            }
            "pin" => {
                let pinned = !app.state::<Viewer>().pinned.load(Ordering::Relaxed);
                pin(app, pinned);
            }
            "login" => {
                let autolaunch = app.autolaunch();
                let enabled = autolaunch.is_enabled().unwrap_or(false);
                let _ = if enabled {
                    autolaunch.disable()
                } else {
                    autolaunch.enable()
                };
                let _ = login_item.set_checked(autolaunch.is_enabled().unwrap_or(!enabled));
            }
            "update" => {
                // A found update is offered at once; otherwise this checks now and says what it found.
                let found = app
                    .state::<Viewer>()
                    .found_update
                    .lock()
                    .ok()
                    .and_then(|found| found.clone());
                match found {
                    Some(release) => updates::offer(app, release),
                    None => updates::check_now(app, channel()),
                }
            }
            "quit" => app.exit(0),
            _ => {}
        })
        .build(app)?;

    Ok(())
}

/// Reuses a running viewer, or starts ours; then shows it in the window or the browser.
fn start_viewer(app: AppHandle, window: WebviewWindow, open_in_browser: bool) {
    std::thread::spawn(move || {
        let viewer = app.state::<Viewer>();
        let port = viewer.port;

        if !server::is_peep(port) {
            if server::is_taken(port) {
                let file = settings::directory()
                    .map(|dir| dir.join("settings.json").display().to_string())
                    .unwrap_or_else(|| "settings.json".to_string());
                fail(
                    &window,
                    format!("Port {port} is used by another program. Change \"port\" in {file}."),
                    String::new(),
                );

                return;
            }

            match server::start(&app, port) {
                Ok(sidecar) => {
                    if let Ok(mut slot) = viewer.sidecar.lock() {
                        *slot = Some(sidecar);
                    }
                }
                Err((message, log)) => {
                    fail(&window, message, log);

                    return;
                }
            }
        }

        let url = viewer.url();
        if open_in_browser {
            // Display mode "browser": the app stays in the tray.
            let _ = app.opener().open_url(url, None::<&str>);
        } else if let Ok(url) = Url::parse(&url) {
            let _ = window.navigate(url);
        }
    });
}

fn fail(window: &WebviewWindow, message: String, log: String) {
    let _ = window.show();
    let _ = window.emit("startup-failed", StartupFailed { message, log });
}

/// The settings file is read again for each check: the channel may have changed in the viewer.
fn channel() -> updates::Channel {
    settings::read().update_channel
}

/// Checks a little after start, then once a day, when "check_updates" is on. A found update
/// turns the tray item into "Update to Peep x.y.z...".
fn check_for_updates_daily(app: &AppHandle) {
    if cfg!(debug_assertions) {
        return;
    }

    let app = app.clone();
    std::thread::spawn(move || {
        std::thread::sleep(Duration::from_secs(10));

        loop {
            let current = settings::read();
            if current.check_updates {
                let found = tauri::async_runtime::block_on(updates::check_quietly(
                    &app,
                    current.update_channel,
                ));
                if let Some(release) = found {
                    let item = app
                        .state::<Viewer>()
                        .update_item
                        .lock()
                        .ok()
                        .and_then(|item| item.clone());
                    if let Some(item) = item {
                        let _ = item.set_text(format!(
                            "Update to Peep {}...",
                            release.tag_name.trim_start_matches('v')
                        ));
                    }
                    if let Ok(mut slot) = app.state::<Viewer>().found_update.lock() {
                        *slot = Some(release);
                    }
                }
            }

            std::thread::sleep(Duration::from_secs(24 * 60 * 60));
        }
    });
}

/// "Check for updates" from the viewer's settings page.
#[tauri::command]
fn check_updates(app: AppHandle) {
    trace("check_updates");
    updates::check_now(&app, channel());
}

/// The Logs tab's "Browse": a native folder picker, starting at the folder typed so far.
/// None when it is cancelled. Async, so the blocking picker runs off the main thread.
#[tauri::command]
async fn pick_folder(app: AppHandle, start: Option<String>) -> Option<String> {
    trace("pick_folder");

    let mut dialog = app.dialog().file().set_title("Choose a log folder");

    if let Some(window) = app.get_webview_window(MAIN) {
        dialog = dialog.set_parent(&window);
    }

    if let Some(start) = start.filter(|path| std::path::Path::new(path).is_dir()) {
        dialog = dialog.set_directory(start);
    }

    dialog
        .blocking_pick_folder()
        .and_then(|folder| folder.into_path().ok())
        .map(|folder| folder.to_string_lossy().into_owned())
}

pub fn run() {
    let settings = settings::read();

    let app = tauri::Builder::default()
        // First: a second launch (or a peep:// link) goes to the running app.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            show_main(app)
        }))
        .plugin(tauri_plugin_deep_link::init())
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_autostart::init(
            MacosLauncher::LaunchAgent,
            None,
        ))
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_dialog::init())
        .plugin(
            tauri_plugin_global_shortcut::Builder::new()
                .with_handler(|app, _shortcut, event| {
                    if event.state() == ShortcutState::Pressed {
                        toggle_main(app);
                    }
                })
                .build(),
        )
        .manage(Viewer {
            port: settings.port,
            sidecar: Mutex::new(None),
            pinned: AtomicBool::new(false),
            pin_item: Mutex::new(None),
            update_item: Mutex::new(None),
            found_update: Mutex::new(None),
        })
        .invoke_handler(tauri::generate_handler![
            set_unread,
            notify,
            show_window,
            hide_window,
            set_pinned,
            check_updates,
            pick_folder
        ])
        .setup(move |app| {
            let handle = app.handle().clone();
            let open_in_browser = settings.display_mode == settings::DisplayMode::Browser;

            let window = WebviewWindowBuilder::new(app, MAIN, WebviewUrl::App("index.html".into()))
                .title("Peep")
                .inner_size(1200.0, 820.0)
                .min_inner_size(560.0, 420.0)
                .visible(!open_in_browser)
                .build()?;

            build_tray(&handle)?;

            if let Err(error) = app.global_shortcut().register(HOTKEY) {
                eprintln!("Peep: the hotkey {HOTKEY} is taken by another app ({error}).");
            }

            #[cfg(any(windows, target_os = "linux"))]
            let _ = app.deep_link().register_all();

            let links = handle.clone();
            app.deep_link().on_open_url(move |event| {
                for link in event.urls() {
                    open_link(&links, &link);
                }
            });

            if let Ok(Some(links)) = app.deep_link().get_current() {
                for link in links {
                    open_link(&handle, &link);
                }
            }

            start_viewer(handle.clone(), window, open_in_browser);
            check_for_updates_daily(&handle);

            Ok(())
        })
        .on_window_event(|window, event| {
            // Closing the window keeps Peep in the tray; Quit is in the tray menu.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .build(tauri::generate_context!())
        .expect("failed to build the Peep desktop app");

    app.run(|app, event| {
        if let RunEvent::Exit = event {
            let viewer = app.state::<Viewer>();
            let sidecar = viewer.sidecar.lock().ok().and_then(|mut slot| slot.take());

            // Only the viewer this app started is stopped; one started from the CLI keeps running.
            if let Some(sidecar) = sidecar {
                server::stop(sidecar, viewer.port);
            }
        }
    });
}

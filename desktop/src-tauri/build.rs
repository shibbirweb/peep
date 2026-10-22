fn main() {
    // Commands the viewer page may call (see capabilities/viewer.json).
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "set_unread",
            "notify",
            "show_window",
            "hide_window",
            "set_pinned",
            "check_updates",
        ]),
    ))
    .expect("failed to run the Tauri build script");
}

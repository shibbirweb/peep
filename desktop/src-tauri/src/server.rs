//! The viewer server: is one running already, and starting or stopping our own.

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use tauri::path::BaseDirectory;
use tauri::{AppHandle, Manager};
use tauri_plugin_shell::process::{CommandChild, CommandEvent};
use tauri_plugin_shell::ShellExt;

// Generous: macOS scans a new app's binaries on first launch, which can take seconds.
const STARTUP_TIMEOUT: Duration = Duration::from_secs(15);
const POLL_INTERVAL: Duration = Duration::from_millis(100);
const MAX_LOG_BYTES: usize = 20_000;

/// Whether a Peep viewer answers on this port (and not another program).
pub fn is_peep(port: u16) -> bool {
    let address = SocketAddr::from(([127, 0, 0, 1], port));
    let Ok(mut stream) = TcpStream::connect_timeout(&address, Duration::from_millis(300)) else {
        return false;
    };

    let _ = stream.set_read_timeout(Some(Duration::from_millis(500)));
    let request = format!(
        "GET /_peep/health HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nConnection: close\r\n\r\n"
    );
    if stream.write_all(request.as_bytes()).is_err() {
        return false;
    }

    let mut answer = String::new();
    let _ = stream.take(4096).read_to_string(&mut answer);

    answer.contains("\"app\":\"peep\"")
}

/// Whether anything at all listens on the port.
pub fn is_taken(port: u16) -> bool {
    TcpStream::connect_timeout(
        &SocketAddr::from(([127, 0, 0, 1], port)),
        Duration::from_millis(300),
    )
    .is_ok()
}

pub struct Sidecar {
    pub child: CommandChild,
}

/// Starts the bundled PHP with the bundled viewer PHAR and waits until it answers.
pub fn start(app: &AppHandle, port: u16) -> Result<Sidecar, (String, String)> {
    let phar = app
        .path()
        .resolve("peep-server.phar", BaseDirectory::Resource)
        .map_err(|error| {
            (
                format!("The viewer is missing from the app: {error}"),
                String::new(),
            )
        })?;

    let (mut events, child) = app
        .shell()
        .sidecar("php")
        .map_err(|error| {
            (
                format!("The bundled PHP is missing: {error}"),
                String::new(),
            )
        })?
        .args([
            "-d".to_string(),
            "zend.assertions=-1".to_string(),
            phar.to_string_lossy().into_owned(),
            "serve".to_string(),
            "--open=none".to_string(),
            format!("--port={port}"),
            // The viewer stops by itself when the app is gone, even after a crash or force quit.
            format!("--exit-with={}", std::process::id()),
        ])
        .spawn()
        .map_err(|error| {
            (
                format!("The viewer could not be started: {error}"),
                String::new(),
            )
        })?;

    let log = Arc::new(Mutex::new(String::new()));
    let output = Arc::clone(&log);
    tauri::async_runtime::spawn(async move {
        while let Some(event) = events.recv().await {
            let text = match event {
                CommandEvent::Stdout(bytes) | CommandEvent::Stderr(bytes) => {
                    String::from_utf8_lossy(&bytes).into_owned()
                }
                CommandEvent::Error(error) => format!("{error}\n"),
                CommandEvent::Terminated(status) => {
                    format!("The viewer stopped (exit code {:?}).\n", status.code)
                }
                _ => continue,
            };

            if let Ok(mut log) = output.lock() {
                if log.len() < MAX_LOG_BYTES {
                    log.push_str(&text);
                }
            }
        }
    });

    let started = Instant::now();
    while started.elapsed() < STARTUP_TIMEOUT {
        if is_peep(port) {
            return Ok(Sidecar { child });
        }

        std::thread::sleep(POLL_INTERVAL);
    }

    let output = log.lock().map(|log| log.clone()).unwrap_or_default();
    let _ = child.kill();

    Err((
        format!(
            "The viewer did not answer on port {port} within {} seconds.",
            STARTUP_TIMEOUT.as_secs()
        ),
        output,
    ))
}

/// Stops our viewer: politely first, so it can save its history, then for good.
pub fn stop(sidecar: Sidecar, port: u16) {
    #[cfg(unix)]
    {
        let _ = std::process::Command::new("kill")
            .arg("-TERM")
            .arg(sidecar.child.pid().to_string())
            .status();

        let started = Instant::now();
        while started.elapsed() < Duration::from_secs(2) && is_taken(port) {
            std::thread::sleep(POLL_INTERVAL);
        }
    }

    #[cfg(not(unix))]
    let _ = port;

    let _ = sidecar.child.kill();
}

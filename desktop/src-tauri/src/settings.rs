//! Reads the viewer's settings.json, the same file `peep-server` uses, for the port and
//! the display mode. Only the viewer writes it.

use std::path::PathBuf;

pub const DEFAULT_PORT: u16 = 23517;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum DisplayMode {
    Window,
    Browser,
}

#[derive(Debug, Clone)]
pub struct Settings {
    pub port: u16,
    pub display_mode: DisplayMode,
    pub update_channel: crate::updates::Channel,
    pub check_updates: bool,
}

impl Default for Settings {
    fn default() -> Self {
        Self {
            port: DEFAULT_PORT,
            display_mode: DisplayMode::Window,
            update_channel: crate::updates::Channel::Stable,
            check_updates: true,
        }
    }
}

/// The settings directory, by the same rules as the viewer's SettingsFile:
/// PEEP_CONFIG_DIR, then %APPDATA%\peep on Windows, then $XDG_CONFIG_HOME/peep, then ~/.config/peep.
pub fn directory() -> Option<PathBuf> {
    let env = |name: &str| std::env::var(name).ok().filter(|value| !value.is_empty());

    if let Some(dir) = env("PEEP_CONFIG_DIR") {
        return Some(PathBuf::from(dir));
    }

    if cfg!(windows) {
        if let Some(app_data) = env("APPDATA") {
            return Some(PathBuf::from(app_data).join("peep"));
        }
    }

    if let Some(xdg) = env("XDG_CONFIG_HOME") {
        return Some(PathBuf::from(xdg).join("peep"));
    }

    env("HOME")
        .or_else(|| env("USERPROFILE"))
        .map(|home| PathBuf::from(home).join(".config").join("peep"))
}

/// A missing or broken file gives the defaults. The desktop app opens a window unless the
/// user chose the browser in the settings.
pub fn read() -> Settings {
    let Some(path) = directory().map(|dir| dir.join("settings.json")) else {
        return Settings::default();
    };

    let Ok(text) = std::fs::read_to_string(path) else {
        return Settings::default();
    };

    parse(&text)
}

pub fn parse(text: &str) -> Settings {
    let Ok(value) = serde_json::from_str::<serde_json::Value>(text) else {
        return Settings::default();
    };

    let port = value
        .get("port")
        .and_then(serde_json::Value::as_u64)
        .and_then(|port| u16::try_from(port).ok())
        .filter(|port| *port > 0)
        .unwrap_or(DEFAULT_PORT);

    let display_mode = match value
        .get("display_mode")
        .and_then(serde_json::Value::as_str)
    {
        Some("browser") => DisplayMode::Browser,
        _ => DisplayMode::Window,
    };

    let update_channel = match value
        .get("update_channel")
        .and_then(serde_json::Value::as_str)
    {
        Some("beta") => crate::updates::Channel::Beta,
        _ => crate::updates::Channel::Stable,
    };

    let check_updates = value
        .get("check_updates")
        .and_then(serde_json::Value::as_bool)
        .unwrap_or(true);

    Settings {
        port,
        display_mode,
        update_channel,
        check_updates,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_port_and_display_mode() {
        let settings = parse(r#"{"port": 9000, "display_mode": "browser"}"#);

        assert_eq!(settings.port, 9000);
        assert_eq!(settings.display_mode, DisplayMode::Browser);
        assert_eq!(settings.update_channel, crate::updates::Channel::Stable);
        assert!(settings.check_updates);
    }

    #[test]
    fn reads_the_update_settings() {
        let settings = parse(r#"{"update_channel": "beta", "check_updates": false}"#);

        assert_eq!(settings.update_channel, crate::updates::Channel::Beta);
        assert!(!settings.check_updates);
    }

    #[test]
    fn broken_values_fall_back_to_the_defaults() {
        let settings = parse(r#"{"port": 70000, "display_mode": 3}"#);

        assert_eq!(settings.port, DEFAULT_PORT);
        assert_eq!(settings.display_mode, DisplayMode::Window);
        assert_eq!(parse("not json").port, DEFAULT_PORT);
    }
}

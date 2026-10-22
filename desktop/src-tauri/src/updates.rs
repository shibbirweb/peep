//! The update checker: finds the newest release on the chosen channel on GitHub, asks before
//! installing it, and installs it with the updater (signed builds) or opens its download page.
//!
//! Stable installs get stable releases (from main); beta installs also get pre-releases (from
//! develop). The channel and the automatic check are viewer settings (`update_channel`,
//! `check_updates`), so the settings page and the tray agree.

use std::time::Duration;

use serde::Deserialize;
use tauri::AppHandle;
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;
use tauri_plugin_updater::UpdaterExt;

const RELEASES: &str = "https://api.github.com/repos/shibbirweb/peep/releases?per_page=30";

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Channel {
    Stable,
    Beta,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Release {
    pub tag_name: String,
    pub html_url: String,
    #[serde(default)]
    pub body: Option<String>,
    pub prerelease: bool,
    pub draft: bool,
}

impl Release {
    pub fn version(&self) -> Option<semver::Version> {
        semver::Version::parse(self.tag_name.trim_start_matches('v')).ok()
    }

    /// The updater's manifest for exactly this release (stable or beta).
    fn manifest_url(&self) -> String {
        format!(
            "https://github.com/shibbirweb/peep/releases/download/{}/latest.json",
            self.tag_name
        )
    }
}

/// The newest release on the channel that is newer than `current`, if any.
pub fn newer(releases: &[Release], channel: Channel, current: &semver::Version) -> Option<Release> {
    releases
        .iter()
        .filter(|release| !release.draft && (channel == Channel::Beta || !release.prerelease))
        .filter_map(|release| release.version().map(|version| (version, release)))
        .filter(|(version, _)| version > current)
        .max_by(|(a, _), (b, _)| a.cmp(b))
        .map(|(_, release)| release.clone())
}

/// What the check found, for the caller to show.
pub enum Found {
    Update(Release),
    UpToDate,
}

pub async fn find(app: &AppHandle, channel: Channel) -> Result<Found, String> {
    let current = app.package_info().version.clone();
    let releases: Vec<Release> = reqwest::Client::builder()
        .user_agent(format!("Peep/{current}"))
        .timeout(Duration::from_secs(15))
        .build()
        .map_err(|error| error.to_string())?
        .get(RELEASES)
        .header("Accept", "application/vnd.github+json")
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map_err(|error| format!("GitHub could not be reached: {error}"))?
        .json()
        .await
        .map_err(|error| format!("GitHub sent something unexpected: {error}"))?;

    Ok(match newer(&releases, channel, &current) {
        Some(release) => Found::Update(release),
        None => Found::UpToDate,
    })
}

/// Whether this build can install updates itself (it carries the update key).
pub fn can_install(app: &AppHandle) -> bool {
    app.config()
        .plugins
        .0
        .get("updater")
        .and_then(|updater| updater.get("pubkey"))
        .and_then(serde_json::Value::as_str)
        .is_some_and(|key| !key.is_empty())
}

/// The first lines of the release notes, for the dialog.
fn summary(release: &Release) -> String {
    let notes: Vec<&str> = release
        .body
        .as_deref()
        .unwrap_or("")
        .lines()
        .map(str::trim)
        .filter(|line| line.starts_with("- "))
        .take(5)
        .collect();

    if notes.is_empty() {
        String::new()
    } else {
        format!("\n\nWhat is new:\n{}", notes.join("\n"))
    }
}

/// Asks, then installs and restarts, or opens the download page.
pub fn offer(app: &AppHandle, release: Release) {
    let current = app.package_info().version.to_string();
    let version = release.tag_name.trim_start_matches('v').to_string();
    let installable = can_install(app);
    let message = format!(
        "Peep {version} is available. You have {current}.{}{}",
        summary(&release),
        if installable {
            ""
        } else {
            "\n\nThis build cannot install updates itself; the download page opens."
        }
    );

    let handle = app.clone();
    app.dialog()
        .message(message)
        .title("Update available")
        .kind(MessageDialogKind::Info)
        .buttons(MessageDialogButtons::OkCancelCustom(
            if installable { "Install and restart".to_string() } else { "Download".to_string() },
            "Later".to_string(),
        ))
        .show(move |accepted| {
            if !accepted {
                return;
            }

            if !installable {
                let _ = handle.opener().open_url(release.html_url.clone(), None::<&str>);

                return;
            }

            tauri::async_runtime::spawn(async move {
                if let Err(error) = install(&handle, &release).await {
                    handle
                        .dialog()
                        .message(format!("The update could not be installed: {error}\n\nThe download page opens instead."))
                        .title("Update failed")
                        .kind(MessageDialogKind::Warning)
                        .show(|_| {});
                    let _ = handle.opener().open_url(release.html_url.clone(), None::<&str>);
                }
            });
        });
}

async fn install(app: &AppHandle, release: &Release) -> Result<(), String> {
    let url = release
        .manifest_url()
        .parse()
        .map_err(|error| format!("{error}"))?;
    let update = app
        .updater_builder()
        .endpoints(vec![url])
        .map_err(|error| error.to_string())?
        .build()
        .map_err(|error| error.to_string())?
        .check()
        .await
        .map_err(|error| error.to_string())?
        .ok_or_else(|| "the release has no update for this platform".to_string())?;

    update
        .download_and_install(|_, _| {}, || {})
        .await
        .map_err(|error| error.to_string())?;
    app.restart();
}

/// "Check for Updates..." in the tray: always says what it found.
pub fn check_now(app: &AppHandle, channel: Channel) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        match find(&app, channel).await {
            Ok(Found::Update(release)) => offer(&app, release),
            Ok(Found::UpToDate) => {
                app.dialog()
                    .message(format!(
                        "Peep {} is the newest {} release.",
                        app.package_info().version,
                        if channel == Channel::Beta {
                            "beta or stable"
                        } else {
                            "stable"
                        }
                    ))
                    .title("Peep is up to date")
                    .show(|_| {});
            }
            Err(error) => {
                app.dialog()
                    .message(error)
                    .title("Could not check for updates")
                    .kind(MessageDialogKind::Warning)
                    .show(|_| {});
            }
        }
    });
}

/// The automatic check: quiet unless there is an update, then a notification and the tray item.
pub async fn check_quietly(app: &AppHandle, channel: Channel) -> Option<Release> {
    match find(app, channel).await {
        Ok(Found::Update(release)) => {
            let _ = app
                .notification()
                .builder()
                .title("Peep update available")
                .body(format!(
                    "Peep {} is ready. Choose Update in the tray menu.",
                    release.tag_name.trim_start_matches('v')
                ))
                .show();
            Some(release)
        }
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn release(tag: &str, prerelease: bool) -> Release {
        Release {
            tag_name: tag.to_string(),
            html_url: String::new(),
            body: None,
            prerelease,
            draft: false,
        }
    }

    #[test]
    fn stable_installs_only_see_stable_releases() {
        let releases = vec![
            release("v1.2.0-beta.1", true),
            release("v1.1.0", false),
            release("v1.0.0", false),
        ];
        let current = semver::Version::parse("1.0.0").unwrap();

        assert_eq!(
            newer(&releases, Channel::Stable, &current).map(|r| r.tag_name),
            Some("v1.1.0".to_string())
        );
        assert_eq!(
            newer(&releases, Channel::Beta, &current).map(|r| r.tag_name),
            Some("v1.2.0-beta.1".to_string())
        );
    }

    #[test]
    fn a_beta_install_moves_on_to_the_finished_release() {
        let releases = vec![release("v1.2.0", false), release("v1.2.0-beta.2", true)];
        let current = semver::Version::parse("1.2.0-beta.2").unwrap();

        assert_eq!(
            newer(&releases, Channel::Beta, &current).map(|r| r.tag_name),
            Some("v1.2.0".to_string())
        );
        assert!(newer(
            &releases,
            Channel::Stable,
            &semver::Version::parse("1.2.0").unwrap()
        )
        .is_none());
    }

    #[test]
    fn drafts_and_odd_tags_are_ignored() {
        let mut draft = release("v9.0.0", false);
        draft.draft = true;
        let releases = vec![draft, release("nightly", false)];

        assert!(newer(
            &releases,
            Channel::Beta,
            &semver::Version::parse("1.0.0").unwrap()
        )
        .is_none());
    }
}

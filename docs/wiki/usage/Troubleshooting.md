# Troubleshooting

## No cards appear

1. **Is the viewer running?** Open `http://127.0.0.1:23517/_peep/health`; it answers `{"app":"peep",...}`.
2. **Is Peep on?** Outside Laravel it is off until `PEEP_ENABLED=true` or a `peep.php` file turns it on. In Laravel it is off in `production` unless `PEEP_ENABLED=true`. `peep()->isActive()` tells you.
3. **Same port?** The app sends to `PEEP_HOST:PEEP_PORT` (default `127.0.0.1:23517`); the viewer listens on the port in its Settings or `--port`.
4. **Was the viewer closed when the app started?** After a failed connection the client waits 30 seconds before trying again, so the app is never slowed down. Send again after that.
5. **Receiving paused?** A banner says so; click **Resume**.

## The app runs in Docker

Use `PEEP_HOST=host.docker.internal`. On Linux this needs a token and the viewer listening beyond your machine; see [Installation](Installation.md#docker-and-other-machines). A `401 invalid_token` answer means the token is missing or wrong, `403 remote_disabled` that the viewer has no token yet.

## Editor links open the wrong file

The path in the container is not the path on your machine. Set `PEEP_REMOTE_PATH` and `PEEP_LOCAL_PATH` in the app, or add a path mapping in Settings, Editor.

## Port 23517 is taken

`peep-server serve` says which program uses it. Choose another port with `--port=9000` (and `PEEP_PORT=9000` in the app), or in Settings.

## SMTP does not start

Port 23525 may be in use (another mail catcher). The `serve` output says so; change *SMTP port* in Settings and restart.

## Webhooks fail

Open the call in the Webhooks tab's delivery log: it shows the request, your app's answer and the error. A status callback to a host outside `localhost`, `127.0.0.1`, `*.test` and `*.localhost` is refused on purpose. *Unknown variable* warnings point at a typo in the template.

## The desktop app does not start the viewer

It shows the viewer's own output when it cannot start. Set `PEEP_DESKTOP_TRACE=1` to see what the window asks of the app.

## Something else

Open an issue at [github.com/shibbirweb/peep/issues](https://github.com/shibbirweb/peep/issues) with the Peep version (`peep-server --version`, or `Version::VERSION`), your PHP version and what you expected.

# Security Policy

## Supported versions

Peep is in early development. Until 1.0.0, only the latest release receives security fixes.

## Reporting a vulnerability

Please do not open a public issue for security problems.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/shibbirweb/peep/security/advisories/new)). Include the affected version, the steps to reproduce, and the impact you expect.

You will get a reply within 7 days. Once a fix is released, the advisory is published with credit to the reporter unless you ask otherwise.

## Scope

Peep is a development tool. The viewer is meant to run on a developer's machine and listens on `127.0.0.1` by default. Issues that are especially relevant:

- A web page or another machine being able to send events to the viewer, change its settings or trigger webhooks.
- The client sending data when it should be disabled (for example in production).
- Sensitive values (passwords, tokens) leaving the app despite redaction.

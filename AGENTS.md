# Repository Guidelines

## Project Structure & Module Organization

- `api/vpn/` contains public PHP VPN subscription endpoints such as `clash.php`, `hiddify.php`, and `sub.php`.
- `private/` contains shared PHP configuration. Keep secrets out of this directory; load them from `.env`.
- `docs/` contains operational documentation. `deploy/` contains deployment examples such as nginx configuration.
- Root files include `.env.example` for required environment variables.
- The project contains only VPN subscription distribution. Runtime services are nginx, PHP-FPM, MySQL/MariaDB, Xray, and sing-box, managed by systemd.

## Build, Test, and Development Commands

- `find . -name "*.php" -print0 | xargs -0 -n1 php -l` checks PHP syntax.

## Coding Style & Naming Conventions

- PHP uses 4-space indentation, small endpoint scripts, and shared configuration via `require_once __DIR__ . '/../../private/app_config.php';`.
- Node settings (reading `.env`, validation, IPv4 resolution) live once in `private/subscription_nodes.php`; `clash.php` and `hiddify.php` only render them, so both formats always carry the same nodes.
- Keep environment variable names uppercase with underscores, matching `.env.example` and `private/app_config.php`.
- Prefer prepared PDO statements for database access and explicit response helpers for HTTP status and content type.

## Testing Guidelines

There is no dedicated test suite in this repository yet. Before submitting changes, run the PHP syntax check above. For endpoint changes, verify authenticated `/sub/client.yaml` and `/sub/mobile.json`, missing/wrong token rejection, and both native client parsers. Legacy token paths require the current deployment token. Preserve the HTTP 410 response from the disabled WireGuard download endpoint. For routing changes, test actual IPv4/IPv6 TCP and UDP egress; a running TUN does not prove an operating-system kill switch remains active after the core exits.

## Commit & Pull Request Guidelines

The current history uses short, imperative commit messages, for example `Keep documentation focused on VPN deployment`. Follow that style and keep commits scoped to one concern. Pull requests should include a concise summary, affected paths, deployment or configuration changes, manual test results, and linked issues when applicable.

## Security & Configuration Tips

Never commit `.env`, credentials, VPN keys, tokens, generated client configs, or audit logs. Update `.env.example` when adding configuration.

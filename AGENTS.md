# Repository Guidelines

## Project Structure & Module Organization

- `api/vpn/` contains public PHP VPN subscription endpoints such as `sub.php`, `clash.php`, and `info.php`.
- `api/ws/` contains the Node.js websocket service, its `package.json`, lockfile, browser test page, and related PHP callbacks.
- `private/` contains shared PHP configuration and CLI helpers. Keep secrets out of this directory; load them from `.env`.
- `docs/` contains operational documentation. `deploy/` contains deployment examples such as nginx configuration.
- Root files include `.env.example` for required environment variables and `ecosystem.config.js` for PM2 process management.

## Build, Test, and Development Commands

- `cd api/ws && npm install` installs websocket service dependencies from `package-lock.json`.
- `cd api/ws && node websocket-server.js` runs the websocket service locally using `.env` from the repository root.
- `pm2 start ecosystem.config.js` starts the production-style PM2 app defined at the root.
- `find . -name "*.php" -not -path "./api/ws/node_modules/*" -print0 | xargs -0 -n1 php -l` checks PHP syntax.
- `cd api/ws && node -c websocket-server.js && node -c keepalive-policy.js` checks JavaScript syntax.

## Coding Style & Naming Conventions

- PHP uses 4-space indentation, small endpoint scripts, and shared configuration via `require_once __DIR__ . '/../../private/app_config.php';`.
- JavaScript uses CommonJS (`require`, `module.exports`), 2-space indentation, semicolons, and environment-driven constants near the top of files.
- Keep environment variable names uppercase with underscores, matching `.env.example` and `private/app_config.php`.
- Prefer prepared PDO statements for database access and explicit response helpers for HTTP status and content type.

## Testing Guidelines

There is no dedicated test suite in this repository yet. Before submitting changes, run the PHP and JavaScript syntax checks above. For endpoint changes, verify responses manually with `curl` using non-production tokens. For websocket changes, run `node websocket-server.js` and test connection, auth, and keepalive behavior with a local client.

## Commit & Pull Request Guidelines

The current history uses short, imperative commit messages, for example `Keep documentation focused on VPN deployment`. Follow that style and keep commits scoped to one concern. Pull requests should include a concise summary, affected paths, deployment or configuration changes, manual test results, and linked issues when applicable. Include screenshots only for browser-facing changes such as `api/ws/index.html`.

## Security & Configuration Tips

Never commit `.env`, credentials, VPN keys, tokens, generated client configs, or audit logs. Update `.env.example` when adding configuration. Keep websocket admin controls protected by `WS_ADMIN_TOKEN` and trusted network settings.

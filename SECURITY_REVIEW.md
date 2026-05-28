# Security Review

This repository contains a WebSocket control service extracted from an older project snapshot. It should be treated as review material until the high-risk device control surface is removed.

## Summary

The service currently mixes three responsibilities in one process:

- Device presence and keepalive tracking.
- Web panel sessions and status queries.
- Remote command forwarding to connected devices.

The third responsibility makes the current code unsuitable for production deployment as-is. Several command paths can affect device privacy, files, sensors, installed applications, screen state, and user input.

## High-Risk Areas

### Remote Device Control

`api/ws/websocket-server.js` forwards many admin commands to devices. The command set includes sensitive operations such as:

- Screen and input control.
- Keylog retrieval.
- SMS and contact access.
- Camera, microphone, and location access.
- File browsing, file transfer, rename, delete, and upload.
- App listing, app opening, app uninstall, and icon hiding.

These capabilities must be removed or replaced with narrowly scoped, user-consented operations before any deployment.

### Broad Admin Channel

Admin traffic is accepted through WebSocket message fields such as `itype`, `subc`, `authToken`, `token`, and related aliases. Although `WS_ADMIN_TOKEN` and command blocklists exist, the service still routes a large number of commands through the same channel.

Required changes:

- Replace ad hoc message authorization with explicit roles and permissions.
- Validate every message against a schema before routing.
- Fail closed when an unknown command, unknown role, or missing token is seen.
- Remove environment-variable toggles that can re-enable prohibited command classes.

### Device Authentication

Device authentication is optional when `WS_DEVICE_AUTH_TOKEN` is empty. This should be mandatory for any non-test environment.

Required changes:

- Refuse service startup if required production secrets are missing.
- Use per-device credentials or signed short-lived tokens instead of one shared token.
- Bind devices to expected account ownership before accepting telemetry.

### In-Memory Device State

The service keeps device and panel mappings in process memory. Restarting the process drops all active state, and a single process owns all routing.

Required changes:

- Make restart behavior explicit and tested.
- Store only non-sensitive operational status.
- Avoid persisting sensitive device payloads in logs or process memory longer than needed.

### Logging

The service writes security audit events and error logs locally. Some command paths also write panel interaction data.

Required changes:

- Keep logs free of tokens, personal data, device payloads, and raw command content.
- Rotate logs and restrict permissions.
- Send security events to a monitored audit sink in production.

### PHP Helper Surface

`private/cli_get_email.php` reads account information from the database for reassignment flows. `api/ws/internal_reassign.php` is intended as an internal-only endpoint.

Required changes:

- Keep PHP helpers outside the public web root where possible.
- Enforce localhost-only access for internal endpoints at the web server and application layers.
- Add database least-privilege users for read-only lookup versus write operations.

## Required Remediation Before Deployment

1. Remove the remote-control command handlers from `handleDeviceCommands`.
2. Keep only explicit, legitimate node functions such as heartbeat, connection status, and authorized operational telemetry.
3. Make admin and device authentication mandatory at process startup.
4. Add message schemas for each allowed message type.
5. Add tests for unauthorized admin messages, unauthorized device messages, invalid origins, oversized payloads, per-IP connection limits, rate limits, and rejected commands.
6. Split configuration into safe defaults and production-required secrets.
7. Verify that `.env`, local tool settings, generated logs, and dependency directories are ignored by Git.
8. Rotate any secret that has ever been placed in local plaintext files or shared outside the runtime secret store.

## Safe Acceptance Criteria

The project can be reconsidered for deployment documentation only after these conditions are true:

- The code no longer contains handlers for keylogging, SMS, contacts, camera, microphone, location, file operations, hidden UI behavior, or app uninstall.
- The WebSocket service fails to start when production authentication secrets are missing.
- Every accepted WebSocket message type has schema validation and an authorization rule.
- Security tests prove that removed command names are rejected.
- Public documentation describes only legitimate node monitoring and user-authorized device management behavior.

## Git Hygiene

The repository intentionally ignores these local or sensitive paths:

- `.env`
- `.env.*` except `.env.example`
- `GIT.MD`
- `.claude/`
- `node_modules/`
- `api/ws/node_modules/`
- runtime logs

Do not remove those ignore rules unless the files are sanitized and reviewed first.

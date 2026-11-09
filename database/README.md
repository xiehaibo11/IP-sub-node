# Database Bootstrap

`schema.sql` contains only the `vpn_clients` structure used by the VPN subscription endpoints.

Import it on a new server after creating the target database. This bootstrap file recreates `vpn_clients`; do not import it over an existing production table:

```sh
mysql -u "$DB_USER" -p "$DB_NAME" < database/schema.sql
```

Production rows are intentionally not committed because `vpn_clients` contains VPN private keys, preshared keys, public keys, and subscription tokens. Move real production data through a private server-to-server backup channel instead of Git.

All times in `vpn_clients` are UTC: the code compares `expires_at` with `UTC_TIMESTAMP()`. Set expiry with `UTC_TIMESTAMP()`, never `NOW()`; the server clock zone is New York, so `NOW() + INTERVAL 2 HOUR` is already expired:

```sql
UPDATE vpn_clients SET expires_at = UTC_TIMESTAMP() + INTERVAL 30 DAY WHERE id = 1;
```

The current public subscriptions use the first enabled, unexpired record. Node credentials come from `.env`. Legacy WireGuard columns remain for compatibility with existing rows and the subscription metadata endpoint.

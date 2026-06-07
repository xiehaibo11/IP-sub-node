# Database Bootstrap

`schema.sql` contains the database structure needed by the PHP endpoints and websocket callbacks.

Import it on a new server after creating the target database:

```sh
mysql -u "$DB_USER" -p "$DB_NAME" < database/schema.sql
```

Production rows are intentionally not committed because `vpn_clients` contains VPN private keys, preshared keys, public keys, and subscription tokens. Move real production data through a private server-to-server backup channel instead of Git.

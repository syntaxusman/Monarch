# ProBackup – Site Migration

Lightweight WordPress plugin for whole-site migration, with a chunked, resumable database import that doesn't time out, and encrypted server-to-server transfer powered by [croc](https://github.com/schollz/croc).

## Features

- **Export** the database, uploads, plugins, themes, must-use plugins and other wp-content folders into a single `.pbk` archive.
- **Transfer** it with croc (an end-to-end encrypted code phrase), as a chunked resumable upload, or as a download.
- **Chunked database import:** one statement at a time from a saved byte offset, into temporary tables, then an atomic `RENAME TABLE` swap. The live site keeps working until the last step.
- **Automatic repairs for cross-server problems:** unknown collations (MySQL 8 ↔ MariaDB), no utf8mb4, key length limits, `max_allowed_packet` (statements are split), duplicate keys, and lost connections.
- **Serialization-safe URL and path replacement** without `unserialize()`. It also handles JSON-escaped and URL-encoded URLs and tables without keys.
- **Retry / Skip / Roll back** on any error.

## Install

Zip the `probackup/` folder (or download a release), then go to **Plugins → Add New → Upload Plugin**.

## Repository layout

```
probackup/          The plugin
  probackup.php     Bootstrap
  includes/         Storage, archive format, DB dump/import, replace, croc, admin controller
  assets/           Admin UI (vanilla JS + CSS)
test/               Docker end-to-end test (MySQL 8 → MariaDB, PHP 7.4 → 8.3)
```

## End-to-end test

Requires Docker and Node.js.

```bash
cd test && docker compose up -d
bash e2e.sh upload   # or: bash e2e.sh croc
docker compose down -v
```

`test/runner.php` is a test harness only. Never deploy it to a real site.

## License

GPL-2.0-or-later

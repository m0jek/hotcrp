All-in-one HotCRP Docker image
==============================

A single container running Nginx, php-fpm, and MariaDB, with all state on one
volume mounted at `/data`. Suitable for trying HotCRP out or for a small
single-conference installation.

Quick start
-----------

From the repository root:

```sh
docker build -f docker/Dockerfile -t hotcrp .

docker run -d --name hotcrp -p 8080:80 -v hotcrp-data:/data \
    -e HOTCRP_SHORT_NAME="MyConf 2026" \
    -e HOTCRP_ADMIN_EMAIL=you@example.org \
    -e HOTCRP_ADMIN_PASSWORD='choose-a-password' \
    hotcrp
```

Then open http://localhost:8080/ and sign in. Or use Compose: edit
`docker/compose.yaml` and run `docker compose -f docker/compose.yaml up -d --build`.

On first start the container initializes MariaDB, creates the `hotcrp`
database with a random password, loads the schema, and creates the
administrator account. Later starts reuse what is on the volume; a newer
image upgrades the database schema automatically on first access.

Configuration
-------------

| Variable | Meaning |
| --- | --- |
| `HOTCRP_SHORT_NAME`, `HOTCRP_LONG_NAME` | Conference names (can also be set in Settings) |
| `HOTCRP_PAPER_SITE` | Public URL of the site, e.g. `https://hotcrp.example.org/`. Set this for any real deployment, and always behind a reverse proxy. |
| `HOTCRP_CONTACT_NAME`, `HOTCRP_CONTACT_EMAIL` | Site contact |
| `HOTCRP_EMAIL_FROM`, `HOTCRP_EMAIL_SENDER` | `From:` header and envelope sender for outgoing mail |
| `HOTCRP_ADMIN_EMAIL`, `HOTCRP_ADMIN_PASSWORD` | Initial system administrator. Only used if the account does not exist yet. |
| `SMTP_HOST`, `SMTP_PORT` (587), `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_TLS` (on), `SMTP_STARTTLS` (on; off for port 465) | SMTP relay for outgoing mail. Use port 587 (STARTTLS) or 465 (implicit TLS); for an unencrypted relay on port 25 set `SMTP_TLS=off`. |
| `PHP_FPM_MAX_CHILDREN` | Maximum number of PHP worker processes (default 32). Each can use up to ~128 MB under load; raise it if the log says `server reached pm.max_children`. |
| `TZ` | Timezone, e.g. `Europe/London` |

**Email.** The container has no mail server of its own; mail is relayed
through `SMTP_HOST`. If `SMTP_HOST` is unset HotCRP sends no mail, which means
users cannot create accounts or reset passwords themselves, so set
`HOTCRP_ADMIN_EMAIL` and `HOTCRP_ADMIN_PASSWORD` to get a usable account.

To test the relay, run
`printf 'Subject: test\n\nHello\n' | docker exec -i hotcrp sendmail -v you@example.org`.
A `network read error: the operation timed out` usually means a TLS mode
mismatch (`SMTP_STARTTLS=on` against port 465, or `SMTP_TLS=on` against a
plain port-25 relay).

**Other options.** Put PHP files that set `$Opt[...]` in `/data/conf/` (for
example `/data/conf/local.php`); they are loaded after the built-in
configuration. `etc/distoptions.php` documents every option.

**HTTPS.** The container serves plain HTTP on port 80. Put it behind a TLS
reverse proxy that sets `X-Forwarded-Proto: https` and passes the `Host`
header through, and set `HOTCRP_PAPER_SITE`.

**Upload size.** The limit is 64 MB per file (`docker/php.ini`,
`docker/nginx.conf`, and `max_allowed_packet` in `docker/mariadb.cnf`).

Data and maintenance
--------------------

`/data` holds `mysql/` (the database, including uploaded documents),
`secrets/dbpassword`, `sessions/`, and `conf/`.

```sh
# Back up the database (restore with `php batch/backupdb.php -r BACKUPFILE`
# inside the container; see the main README)
docker exec hotcrp php batch/backupdb.php > backup.sql

# Any other batch script
docker exec hotcrp php batch/saveusers.php -u pc@example.org --roles pc

# SQL prompt
docker exec -it hotcrp mariadb hotcrp
```

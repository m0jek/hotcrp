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
| `HOTCRP_DISABLE_NEW_USERS` | Set to `true` to stop people creating their own accounts. Administrators can still add users (Users page, or `batch/saveusers.php`). |
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

Single sign-on with Authentik
-----------------------------

HotCRP can sign users in through an OpenID Connect provider such as
[Authentik](https://goauthentik.io/). By default this adds a sign-in button
next to normal password sign-in, so people outside your organization (authors,
external reviewers) can still use HotCRP accounts. See
`devel/manual/oauth.md` for every option.

**In Authentik**, create an *OAuth2/OpenID Provider* and an *Application* that
uses it:

* Client type: confidential. Note the client ID and secret.
* Redirect URI: `https://hotcrp.example.org/oauth` (your `HOTCRP_PAPER_SITE`
  plus `oauth`)
* Scopes: the defaults, `openid`, `email`, and `profile`

**In the container**, create `/data/conf/authentik.php`. Replace the
hostnames, the application slug (`hotcrp` below), and the credentials:

```php
<?php
$Opt["oAuthProviders"][] = '{
    "name": "Authentik",
    "issuer": "https://auth.example.org/application/o/hotcrp/",
    "auth_uri": "https://auth.example.org/application/o/authorize/",
    "token_uri": "https://auth.example.org/application/o/token/",
    "client_id": "CLIENT_ID",
    "client_secret": "CLIENT_SECRET",
    "button_html": "Sign in with Authentik"
}';
```

For example, write it locally as `authentik.php`, then copy it in and make it
readable only by the web server:

```sh
docker exec -i hotcrp sh -c 'cat > /data/conf/authentik.php && chown root:www-data /data/conf/authentik.php && chmod 640 /data/conf/authentik.php' < authentik.php
```

Configuration files are read on every request, so no restart is needed. The
three URLs can be confirmed at
`https://auth.example.org/application/o/hotcrp/.well-known/openid-configuration`.

Things to check:

* `HOTCRP_PAPER_SITE` must be the public `https://` address. HotCRP derives
  the redirect URI from it, and it must exactly match the one registered in
  Authentik.
* The container must reach Authentik over HTTPS; HotCRP refuses a
  `token_uri` that is not `https://`.
* HotCRP requires the ID token's `email_verified` claim to be `true`. If
  sign-in fails with an email verification error, add an Authentik scope
  mapping that returns `email_verified: true` (appropriate when your directory
  manages addresses), or add `"allow_unverified_email": true` to the provider.
* With `HOTCRP_DISABLE_NEW_USERS=true`, a first-time Authentik sign-in is
  refused unless an account with that email already exists. To let anyone in
  Authentik sign in while preventing other signups, leave
  `HOTCRP_DISABLE_NEW_USERS` unset and add `$Opt["loginType"] = "oauth";` to
  `authentik.php`. That makes Authentik the *only* way to sign in, including
  for administrators.

**Roles from Authentik groups (optional).** HotCRP can grant roles from the
token's `groups` claim. Add a `group_roles` object to the provider JSON; for
example:

```json
"group_roles": {
    "hotcrp-admins": "+sysadmin",
    "hotcrp-chairs": "+chair",
    "hotcrp-pc": "pc"
}
```

Roles are added at each sign-in but not removed unless you also set
`reset_roles` (see `devel/manual/oauth.md`).

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

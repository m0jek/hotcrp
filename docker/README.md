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
| `HOTCRP_MULTICONFERENCE` | Set to `true` to serve several conferences, each at `/CONFID/`. See [Several conferences](#several-conferences). |
| `HOTCRP_DEFAULT_CONFERENCE` | In multiconference mode, the conference that `/` and unrecognized URLs redirect to. |
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

**Custom stylesheets.** Keep your CSS files in a folder on the host and mount
it at `/srv/hotcrp/stylesheets/local`: add
`-v /path/to/hotcrp-css:/srv/hotcrp/stylesheets/local:ro` to `docker run`, or
the same mapping under `volumes:` in Compose. Then list each file in
`/data/conf/style.php`:

```php
<?php
$Opt["stylesheets"][] = "stylesheets/local/custom.css";
```

These load after HotCRP's own `style.css`, so their rules take precedence, and
edits show on the next page load. Do not copy files into
`/srv/hotcrp/stylesheets` inside the container; that is part of the image, so
they would be lost when the container is recreated. For example, to make the
footer, shown in small text at the bottom right of each page, larger:

```css
#p-footer { font-size: 1rem; }
```

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
* HotCRP requires the ID token's `email_verified` claim to be `true`, and
  Authentik's default email mapping does not send that, so sign-in fails with
  "The Authentik authenticator hasn’t verified your email". Add
  `"allow_unverified_email": true,` to the provider JSON in `authentik.php`.
  This makes HotCRP trust the email Authentik reports, so first make sure
  users cannot change their own email address in Authentik (System →
  Settings); otherwise a user could set a PC chair's address and sign in as
  them. (A custom Authentik scope mapping that returns `email_verified: true`
  is an alternative, but it must replace the provider's default `email`
  mapping, and in at least one deployment it did not take effect.)
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

Create groups with exactly these names in Authentik (Directory → Groups) and
add users to them; Authentik's default `profile` scope sends each user's
groups. Roles are applied when the user next signs in with Authentik, not
when they sign in with a password or are added to a group. Chairs are
automatically PC members too.

Roles are only ever added, so removing someone from `hotcrp-pc` in Authentik
does not remove them from the PC. To make HotCRP follow the groups, also set
`reset_roles`, the roles to clear at each Authentik sign-in before the
groups' roles are applied:

```json
"reset_roles": "pc chair",
"group_roles": {
    "hotcrp-admins": "+sysadmin",
    "hotcrp-chairs": "+chair",
    "hotcrp-pc": "pc"
}
```

Each sign-in now gives a user exactly the PC and chair roles their groups
grant. Roles not listed in `reset_roles` are never cleared: here a sysadmin
who leaves `hotcrp-admins` keeps the role until it is removed in HotCRP (add
`sysadmin` to `reset_roles` to change that, but make sure an administrator
can still sign in). In `group_roles`, `"pc"` and `"+pc"` mean the same.
Roles assigned by hand in HotCRP are cleared too when the
user next signs in through Authentik, so with `reset_roles` manage those
roles in Authentik only. See `devel/manual/oauth.md` for details.

`/data/conf/authentik.php` applies to every conference, so in multiconference
mode a member of `hotcrp-pc` becomes a PC member of each conference they sign
in to. For a separate PC per conference, define the provider in each
`/data/conferences/CONFID.php` instead, with its own group names (such as
`"cb2027-pc": "pc"`), and remove `/data/conf/authentik.php`.

Several conferences
-------------------

Set `HOTCRP_MULTICONFERENCE=true` to serve several conferences from one
container. Each conference is served under its own path, such as
`https://hotcrp.example.org/ws2027/`, and has its own database, so its own
settings, submissions, reviews, and user accounts. Being an administrator of
one conference gives no access to another.

**Adding a conference.** Run, in the running container:

```sh
docker exec hotcrp hotcrp-add-conference ws2027 --name "WS 2027" --long-name "CHERI Workshop 2027"
```

The conference ID (`ws2027`) becomes the URL path and the database name. It
may contain lowercase letters, digits, `-`, and `_`. If
`HOTCRP_ADMIN_EMAIL` (and `HOTCRP_ADMIN_PASSWORD`) are set, that account
becomes the new conference's administrator. Names can be changed later on the
conference's Settings page.

**Copying a conference.** To start a new conference from an existing one,
for example next year's edition, run:

```sh
docker exec hotcrp hotcrp-copy-conference ws2026 ws2027 --name "WS 2027" --long-name "CHERI Workshop 2027"
```

The new conference gets a complete copy of the old one's database
(submissions, uploaded documents, reviews, comments, tags, user accounts and
passwords, and settings) and of its `/data/conferences/CONFID.php`. The old
conference is not changed. Without `--name` and `--long-name` the copy keeps
the old names. Afterwards, update the copy's deadlines on its Settings page,
check its `/data/conferences/CONFID.php` (for example `conferenceListDate`),
and, with Authentik, add its redirect URI (see below).

To copy only the settings (submission form, review form, and so on) and not
the submissions, reviews, or users, create an empty conference and copy the
settings into it:

```sh
docker exec hotcrp hotcrp-add-conference ws2027 --name "WS 2027"
docker exec hotcrp sh -c 'php batch/settings.php -n ws2026 > /tmp/settings.json'
docker exec hotcrp php batch/settings.php -n ws2027 save /tmp/settings.json
```

The exported settings include the old conference's names and deadlines; edit
`/tmp/settings.json` before saving it, or change them afterwards on the new
conference's Settings page.

**The site root.** `/` lists the conferences. Set
`HOTCRP_DEFAULT_CONFERENCE=ws2027` to redirect `/` to that conference
instead; any URL that does not start with a conference ID (for example
`/paper/12`) is then redirected into it too.

**Order of the list.** The newest conference comes first, judged by each
conference's submission deadline (set on its Settings page). Conferences with
no deadline yet, usually new ones, appear at the top. To place a conference
by another date, such as when it takes place, set `conferenceListDate` in
its `/data/conferences/CONFID.php`:

```php
$Opt["conferenceListDate"] = "2027-06-15";
```

Files created by `hotcrp-add-conference` already contain this line, commented
out; remove the leading `//` and change the date. Older conference files
don't have it, so add the line by hand.

**A link to the conference list.** To show an "All conferences" link in the
footer of every page, create `/data/conf/footer.php`:

```php
<?php
$Opt["extraFooter"] = '<a href="/">All conferences</a>'
    . ' <span class="barsep">|</span> ';
```

The footer is shown in small text at the bottom right of each conference
page (not on the conference list itself); see
[Custom stylesheets](#configuration) to make it larger. The value is HTML.
Keep the trailing separator, which comes before HotCRP's own footer link, and write any `'` in the text as `\'`. This link only reaches
the list when `HOTCRP_DEFAULT_CONFERENCE` is unset; otherwise `/` redirects to
the default conference.

**Switching an existing installation.** When multiconference mode is turned
on for a data volume that already holds a conference, that conference is kept
as conference `hotcrp` (its database name) and moves to `/hotcrp/`. Set
`HOTCRP_DEFAULT_CONFERENCE=hotcrp` so links in email that has already been
sent keep working.

**Configuration.** In multiconference mode:

* `HOTCRP_SHORT_NAME` and `HOTCRP_LONG_NAME` are ignored; each conference
  has its own names. (When an existing conference is switched over, they are
  copied into its options file.)
* `HOTCRP_PAPER_SITE` is the site root, e.g. `https://hotcrp.example.org/`;
  each conference's own URL is that plus its ID.
* The other variables, and files in `/data/conf/`, apply to every
  conference. Options for one conference go in
  `/data/conferences/CONFID.php`, which `hotcrp-add-conference` creates.

**Authentik.** One `/data/conf/authentik.php` serves every conference, but
each conference has its own redirect URI, `https://hotcrp.example.org/CONFID/oauth`.
Add each one to the provider's Redirect URIs in Authentik. When switching an
existing installation, replace its old `…/oauth` redirect URI with
`…/hotcrp/oauth`.

**Command-line tools** need the conference: add `-n CONFID`, for example
`docker exec hotcrp php batch/backupdb.php -n ws2027 > ws2027.sql`.

**Removing a conference.** Delete `/data/conferences/CONFID.php`; the
conference stops being served, but its database is kept. To delete its data
permanently, also run
``docker exec hotcrp mariadb -e 'DROP DATABASE `CONFID`'``.

Data and maintenance
--------------------

`/data` holds `mysql/` (the databases, including uploaded documents),
`secrets/dbpassword`, `sessions/`, `conf/`, and, in multiconference mode,
`conferences/`.

```sh
# Back up the database (restore with `php batch/backupdb.php -r BACKUPFILE`
# inside the container; see the main README)
docker exec hotcrp php batch/backupdb.php > backup.sql

# Any other batch script (add `-n CONFID` in multiconference mode)
docker exec hotcrp php batch/saveusers.php -u pc@example.org --roles pc

# SQL prompt
docker exec -it hotcrp mariadb hotcrp
```

#!/bin/bash
# Entrypoint for the all-in-one HotCRP image: initializes the data volume on
# first start, then runs MariaDB, php-fpm, and Nginx until one of them exits
# or the container is stopped.
set -euo pipefail

DATA=/data
DBNAME=hotcrp
DBUSER=hotcrp
PWFILE=$DATA/secrets/dbpassword

log () { echo "[hotcrp] $*" >&2; }

mkdir -p "$DATA/mysql" "$DATA/conf" "$DATA/sessions" "$DATA/secrets" /run/mysqld /run/php
chown mysql:mysql "$DATA/mysql" /run/mysqld
chown www-data:www-data "$DATA/sessions"
chmod 700 "$DATA/sessions"
chown root:www-data "$DATA/secrets"
chmod 750 "$DATA/secrets"

## MariaDB
if [ ! -d "$DATA/mysql/mysql" ]; then
    log "Initializing MariaDB data directory..."
    mariadb-install-db --user=mysql --datadir="$DATA/mysql" \
        --auth-root-authentication-method=socket --skip-test-db >/dev/null
fi

mariadbd --user=mysql &
mariadb_pid=$!

for i in $(seq 1 60); do
    if mariadb-admin ping --silent >/dev/null 2>&1; then break; fi
    if ! kill -0 "$mariadb_pid" 2>/dev/null; then
        log "MariaDB failed to start"; exit 1
    fi
    sleep 1
done
mariadb-admin ping --silent >/dev/null || { log "MariaDB did not become ready"; exit 1; }

## HotCRP database, user, and schema
if [ ! -s "$PWFILE" ]; then
    (umask 077 && head -c 24 /dev/urandom | base64 | tr -d '/+=\n' > "$PWFILE")
fi
chown root:www-data "$PWFILE"
chmod 640 "$PWFILE"

mariadb <<__EOF__
CREATE DATABASE IF NOT EXISTS \`$DBNAME\` DEFAULT CHARACTER SET utf8mb4;
CREATE OR REPLACE USER '$DBUSER'@'localhost' IDENTIFIED BY '$(cat "$PWFILE")';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX,
    REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES
    ON \`$DBNAME\`.* TO '$DBUSER'@'localhost';
GRANT RELOAD ON *.* TO '$DBUSER'@'localhost';
__EOF__

if [ -z "$(mariadb -N -e "SHOW TABLES LIKE 'Settings'" "$DBNAME")" ]; then
    log "Creating HotCRP database schema..."
    mariadb "$DBNAME" < /srv/hotcrp/src/schema.sql
fi

## Outgoing mail (msmtp provides /usr/sbin/sendmail)
if [ -n "${SMTP_HOST:-}" ]; then
    (umask 077 && {
        echo "defaults"
        echo "syslog off"
        echo "account default"
        echo "host $SMTP_HOST"
        echo "port ${SMTP_PORT:-587}"
        echo "tls ${SMTP_TLS:-on}"
        echo "tls_starttls ${SMTP_STARTTLS:-on}"
        echo "allow_from_override on"
        if [ -n "${HOTCRP_EMAIL_SENDER:-${HOTCRP_EMAIL_FROM:-${HOTCRP_CONTACT_EMAIL:-}}}" ]; then
            echo "from ${HOTCRP_EMAIL_SENDER:-${HOTCRP_EMAIL_FROM:-$HOTCRP_CONTACT_EMAIL}}"
        fi
        if [ -n "${SMTP_USER:-}" ]; then
            echo "auth on"
            echo "user $SMTP_USER"
            echo "password ${SMTP_PASSWORD:-}"
        else
            echo "auth off"
        fi
    } > /etc/msmtprc)
    chown root:www-data /etc/msmtprc
    chmod 640 /etc/msmtprc
else
    rm -f /etc/msmtprc
    log "SMTP_HOST is not set: HotCRP will not send email"
fi

## Optional initial administrator (created once; see bootstrap-admin.php)
if [ -n "${HOTCRP_ADMIN_EMAIL:-}" ]; then
    php /usr/local/lib/hotcrp/bootstrap-admin.php \
        || log "Could not create administrator $HOTCRP_ADMIN_EMAIL"
elif [ -z "${SMTP_HOST:-}" ]; then
    log "Neither SMTP_HOST nor HOTCRP_ADMIN_EMAIL is set: nobody can sign in."
    log "Set HOTCRP_ADMIN_EMAIL and HOTCRP_ADMIN_PASSWORD to create an administrator."
fi

## Run a command instead of the web server (e.g. `docker run ... hotcrp php batch/...`)
if [ $# -gt 0 ]; then
    status=0
    "$@" || status=$?
    mariadb-admin shutdown || true
    wait "$mariadb_pid" || true
    exit $status
fi

## Web server
php-fpm -F &
fpm_pid=$!
nginx -g 'daemon off;' &
nginx_pid=$!

shutdown () {
    trap - TERM INT
    log "Shutting down..."
    kill -QUIT "$nginx_pid" 2>/dev/null || true
    kill -QUIT "$fpm_pid" 2>/dev/null || true
    wait "$nginx_pid" "$fpm_pid" 2>/dev/null || true
    mariadb-admin shutdown 2>/dev/null || kill -TERM "$mariadb_pid" 2>/dev/null || true
    wait "$mariadb_pid" 2>/dev/null || true
}
trap 'shutdown; exit 0' TERM INT

log "HotCRP is up"
status=0
wait -n "$mariadb_pid" "$fpm_pid" "$nginx_pid" || status=$?
log "A service exited unexpectedly (status $status)"
shutdown
exit 1

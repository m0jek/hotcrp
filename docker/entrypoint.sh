#!/bin/bash
# Entrypoint for the all-in-one HotCRP image: initializes the data volume on
# first start, then runs MariaDB, php-fpm, and Nginx until one of them exits
# or the container is stopped.
set -euo pipefail
. /usr/local/lib/hotcrp/functions.sh

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

## HotCRP databases, user, and schema
ensure_db_user
conferences=()
if is_multiconference; then
    mkdir -p "$CONFDIR"
    chown root:www-data "$CONFDIR"
    chmod 750 "$CONFDIR"
    # On switching from single-conference mode, keep the existing conference
    # as conference "hotcrp" (its database name)
    if ! compgen -G "$CONFDIR/*.php" >/dev/null \
       && [ -n "$(mariadb -N -e "SHOW TABLES FROM \`hotcrp\` LIKE 'Settings'" 2>/dev/null)" ]; then
        write_conference_file hotcrp "${HOTCRP_SHORT_NAME:-}" "${HOTCRP_LONG_NAME:-}"
        log "Registered the existing conference as \"hotcrp\", now served at /hotcrp/"
        log "Set HOTCRP_DEFAULT_CONFERENCE=hotcrp to keep its old URLs working"
    fi
    for f in "$CONFDIR"/*.php; do
        [ -e "$f" ] || continue
        id="$(basename "$f" .php)"
        if ! valid_confid "$id"; then
            log "Ignoring $f: invalid conference ID"
            continue
        fi
        ensure_database "$id"
        conferences+=("$id")
    done
    if [ ${#conferences[@]} -eq 0 ]; then
        log "Multiconference mode: no conferences yet; add one with"
        log "  docker exec <container> hotcrp-add-conference CONFID --name \"Short name\""
    else
        log "Multiconference mode: conferences ${conferences[*]}"
    fi
    ln -sf /etc/nginx/hotcrp/multi.conf /etc/nginx/sites-enabled/hotcrp.conf
else
    ensure_database hotcrp
    ln -sf /etc/nginx/hotcrp/single.conf /etc/nginx/sites-enabled/hotcrp.conf
fi

## Outgoing mail (msmtp provides /usr/sbin/sendmail)
if [ -n "${SMTP_HOST:-}" ]; then
    smtp_port="${SMTP_PORT:-587}"
    smtp_tls="${SMTP_TLS:-on}"
    # Port 465 is implicit TLS (SMTPS): the server expects a TLS handshake
    # immediately, so STARTTLS must be off or the connection hangs until it
    # times out. Port 25 relays often have no TLS at all.
    case "$smtp_port" in
    465) smtp_starttls="${SMTP_STARTTLS:-off}";;
    *) smtp_starttls="${SMTP_STARTTLS:-on}";;
    esac
    log "Mail relay: $SMTP_HOST:$smtp_port (tls $smtp_tls, starttls $smtp_starttls)"
    (umask 077 && {
        echo "defaults"
        echo "syslog off"
        echo "timeout 30"
        echo "account default"
        echo "host $SMTP_HOST"
        echo "port $smtp_port"
        echo "tls $smtp_tls"
        echo "tls_starttls $smtp_starttls"
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
    if is_multiconference; then
        for id in "${conferences[@]}"; do
            bootstrap_admin "$id"
        done
    else
        bootstrap_admin
    fi
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
export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-32}"
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

# Shell functions shared by the entrypoint and `hotcrp-add-conference`.
# Sourced, not executed.

DATA=/data
DBUSER=hotcrp
PWFILE=$DATA/secrets/dbpassword
CONFDIR=$DATA/conferences

log () { echo "[hotcrp] $*" >&2; }

is_multiconference () {
    case "${HOTCRP_MULTICONFERENCE:-}" in
    1|[Tt]rue|[Yy]es|[Oo]n) return 0;;
    *) return 1;;
    esac
}

# A conference ID is also its URL path component and its database name.
valid_confid () {
    [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,47}$ ]] || return 1
    case "$1" in
    mysql|sys|information_schema|performance_schema|test|images|scripts|stylesheets)
        return 1;;
    esac
}

# (Re)create the HotCRP database user with the password from $PWFILE.
# This drops the user's existing grants; `ensure_database` restores them.
ensure_db_user () {
    if [ ! -s "$PWFILE" ]; then
        (umask 077 && head -c 24 /dev/urandom | base64 | tr -d '/+=\n' > "$PWFILE")
    fi
    chown root:www-data "$PWFILE"
    chmod 640 "$PWFILE"
    mariadb <<__EOF__
CREATE OR REPLACE USER '$DBUSER'@'localhost' IDENTIFIED BY '$(cat "$PWFILE")';
GRANT RELOAD ON *.* TO '$DBUSER'@'localhost';
__EOF__
}

# Create database $1 if needed, grant the HotCRP user access to it, and load
# the schema if it is empty.
ensure_database () {
    local db="$1"
    local grantdb="${db//_/\\_}"    # `_` is a wildcard in GRANT database names
    mariadb <<__EOF__
CREATE DATABASE IF NOT EXISTS \`$db\` DEFAULT CHARACTER SET utf8mb4;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX,
    REFERENCES, ALTER, LOCK TABLES, CREATE TEMPORARY TABLES
    ON \`$grantdb\`.* TO '$DBUSER'@'localhost';
__EOF__
    if [ -z "$(mariadb -N -e "SHOW TABLES LIKE 'Settings'" "$db")" ]; then
        log "Creating HotCRP database schema in database $db..."
        mariadb "$db" < /srv/hotcrp/src/schema.sql
    fi
}

# Write $CONFDIR/$1.php, the per-conference options file whose existence
# defines the conference. $2 and $3 are optional short and long names.
write_conference_file () {
    local id="$1"
    mkdir -p "$CONFDIR"
    chown root:www-data "$CONFDIR"
    chmod 750 "$CONFDIR"
    HC_ID="$id" HC_NAME="${2:-}" HC_LONG="${3:-}" php -r '
        $id = getenv("HC_ID");
        echo "<?php\n",
            "// Options for the \"{$id}\" conference, served at /{$id}/. Values set on\n",
            "// the conference’s Settings page take precedence over these. Options in\n",
            "// /data/conf/*.php apply to every conference; etc/distoptions.php lists\n",
            "// every option.\n";
        if (($n = getenv("HC_NAME")) !== "") {
            echo "\$Opt[\"shortName\"] = ", var_export($n, true), ";\n";
        }
        if (($n = getenv("HC_LONG")) !== "") {
            echo "\$Opt[\"longName\"] = ", var_export($n, true), ";\n";
        }
        echo "\n",
            "// The conference list at / shows the newest conference first, judged by\n",
            "// each conference’s submission deadline. To place this conference by\n",
            "// another date instead, such as when it takes place, uncomment this:\n",
            "// \$Opt[\"conferenceListDate\"] = \"2027-06-15\";\n";' > "$CONFDIR/$id.php"
    chown root:www-data "$CONFDIR/$id.php"
    chmod 640 "$CONFDIR/$id.php"
}

# Create the initial administrator from HOTCRP_ADMIN_EMAIL, if set, in
# conference $1 (multiconference mode) or the only conference.
bootstrap_admin () {
    [ -n "${HOTCRP_ADMIN_EMAIL:-}" ] || return 0
    php /usr/local/lib/hotcrp/bootstrap-admin.php "$@" \
        || log "Could not create administrator $HOTCRP_ADMIN_EMAIL${1:+ in conference $1}"
}

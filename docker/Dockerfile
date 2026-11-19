# All-in-one HotCRP image: Nginx + php-fpm + MariaDB in a single container.
#
# Build from the repository root:
#     docker build -f docker/Dockerfile -t hotcrp .
# See docker/README.md for how to run it.

FROM debian:trixie-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        mariadb-server \
        msmtp-mta \
        nginx-light \
        php-cli php-fpm php-mysql php-intl php-curl php-mbstring php-zip \
        poppler-utils \
        zip unzip \
    && rm -rf /var/lib/apt/lists/* /var/lib/mysql \
    && ln -s /usr/sbin/php-fpm8.* /usr/sbin/php-fpm \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY docker/nginx.conf /etc/nginx/hotcrp/single.conf
COPY docker/nginx-multi.conf /etc/nginx/hotcrp/multi.conf
COPY docker/nginx-fastcgi.conf /etc/nginx/hotcrp/fastcgi.conf
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/90-hotcrp.cnf
COPY docker/php.ini /tmp/hotcrp-php.ini
COPY docker/php-fpm-pool.conf /tmp/hotcrp-pool.conf
RUN set -e; \
    phpdir="$(echo /etc/php/8.*)"; \
    cp /tmp/hotcrp-php.ini "$phpdir/fpm/conf.d/90-hotcrp.ini"; \
    cp /tmp/hotcrp-php.ini "$phpdir/cli/conf.d/90-hotcrp.ini"; \
    mv /tmp/hotcrp-pool.conf "$phpdir/fpm/pool.d/zz-hotcrp.conf"; \
    sed -i 's|^error_log = .*|error_log = /proc/self/fd/2|; s|^;\?pid = .*|pid = /run/php/php-fpm.pid|' "$phpdir/fpm/php-fpm.conf"; \
    rm /tmp/hotcrp-php.ini

COPY . /srv/hotcrp
COPY docker/options.php /srv/hotcrp/conf/options.php
COPY docker/entrypoint.sh /usr/local/bin/hotcrp-entrypoint
COPY docker/hotcrp-add-conference /usr/local/bin/hotcrp-add-conference
COPY docker/functions.sh docker/bootstrap-admin.php docker/multiconference.php \
    /usr/local/lib/hotcrp/
RUN chmod 755 /usr/local/bin/hotcrp-entrypoint /usr/local/bin/hotcrp-add-conference \
    && chmod 644 /srv/hotcrp/conf/options.php

WORKDIR /srv/hotcrp
VOLUME /data
EXPOSE 80
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/hotcrp-entrypoint"]

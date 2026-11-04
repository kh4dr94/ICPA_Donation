#!/usr/bin/env bash
# Disposable integration tests only. No host ports or live payment credentials.
set -euo pipefail
task_root="$(cd "$(dirname "$0")/.." && pwd)"
task_name="icpa-platform-tests-$$"
task_db="${task_name}-db"
task_wp="${task_name}-wp"
wordpress_image="${ICPA_WORDPRESS_IMAGE:-wordpress:php8.3-apache@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2}"
mariadb_image="${ICPA_MARIADB_IMAGE:-mariadb:11@sha256:c4124d1afa875e47f2892edf26b482fa54f796e2f342d3717a04ca091a22a9bd}"
# Target the managed local daemon; preserve Docker registry/proxy configuration.
dlocal() {
    env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
        docker --host=unix:///var/run/docker.sock "$@"
}
cleanup() {
    dlocal rm -fv "$task_wp" "$task_db" >/dev/null 2>&1 || true
    dlocal network rm "$task_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT
dlocal info --format '{{.ServerVersion}}'
dlocal network create "$task_name" >/dev/null
dlocal run -d --name "$task_db" --network "$task_name" --network-alias icpa-db \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=wordpress "$mariadb_image" >/dev/null
dlocal run -d --name "$task_wp" --network "$task_name" \
    -e WORDPRESS_DB_HOST=icpa-db -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD= -e WORDPRESS_DB_NAME=wordpress \
    --mount "type=bind,src=$task_root/icpa-fundraising,dst=/var/www/html/wp-content/plugins/icpa-fundraising,readonly" \
    --mount "type=bind,src=$task_root/icpa-square-donations,dst=/var/www/html/wp-content/plugins/icpa-square-donations,readonly" \
    --mount "type=bind,src=$task_root/tests,dst=/test,readonly" "$wordpress_image" >/dev/null
ready=0
for attempt in $(seq 1 30); do
    if dlocal exec "$task_db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && \
       dlocal exec "$task_wp" test -f /var/www/html/wp-config.php; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    dlocal logs --tail 30 "$task_db"
    dlocal logs --tail 30 "$task_wp"
    exit 1
fi
dlocal exec -i "$task_wp" php <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = 'localhost';
define('WP_SITEURL', 'http://localhost');
define('WP_HOME', 'http://localhost');
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
wp_install('ICPA test', 'icpa_test_admin', 'test@example.invalid', false, '', wp_generate_password(32, true, true));
echo 'WordPress ' . get_bloginfo('version') . ' installed' . PHP_EOL;
PHP
dlocal exec "$task_wp" php /test/integration.php
dlocal exec "$task_wp" php /test/platform-integration.php
dlocal exec "$task_wp" php /test/donations-integration.php
dlocal exec "$task_wp" php /test/locations-integration.php
dlocal exec "$task_wp" php /test/recurring-integration.php
dlocal exec "$task_wp" php /test/security-integration.php
dlocal exec "$task_wp" php /test/fees-integration.php
dlocal cp "$task_wp:/tmp/icpa-fee-view.html" /tmp/icpa-fee-view.html
dlocal exec "$task_wp" php /test/card-integration.php
dlocal cp "$task_wp:/tmp/icpa-card-view.html" /tmp/icpa-card-view.html
dlocal exec -e ICPA_RUN_DEMO=1 "$task_wp" php /test/platform-demo.php || { dlocal logs --tail 60 "$task_wp"; exit 1; }
dlocal cp "$task_wp:/tmp/icpa-platform-views.json" "${ICPA_VIEWS_EXPORT:-/tmp/icpa-platform-views.json}"

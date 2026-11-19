#!/usr/bin/env bash
#
# Build a throwaway WordPress with the theme and the portal, seed the worked
# example from the spec, and serve it on 127.0.0.1:8088 for the E2E scripts.
#
# Usage: bash diromax-portal/tests/e2e/setup.sh <work-dir>
# Needs: PHP 8.3, a MySQL/MariaDB user wp/wp with rights on database wp_e2e,
#        composer install already run (WordPress comes from vendor/).
#
set -euo pipefail

work="$1"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
here="$repo/diromax-portal/tests/e2e"
wp="$work/wp"
db_host="${DX_E2E_DB_HOST:-127.0.0.1}"

rm -rf "$wp"
mkdir -p "$work/mails" "$work/private" "$work/e2e"
cp -r "$repo/vendor/roots/wordpress-no-content" "$wp"
mkdir -p "$wp/wp-content/themes" "$wp/wp-content/plugins" "$wp/wp-content/mu-plugins"
ln -s "$repo/diromax-theme" "$wp/wp-content/themes/diromax"
ln -s "$repo/diromax-portal" "$wp/wp-content/plugins/diromax-portal"

salts="$(php -r 'foreach (array("AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT") as $k) { echo "define( \"$k\", \"" . bin2hex( random_bytes( 32 ) ) . "\" );\n"; }')"
key="$(php -r 'echo base64_encode( random_bytes( 32 ) );')"

cat > "$wp/wp-config.php" <<CONFIG
<?php
define( 'DB_NAME', 'wp_e2e' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'wp' );
define( 'DB_HOST', '$db_host' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$salts
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$work/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DX_PORTAL_KEY', '$key' );
define( 'DX_PORTAL_PRIVATE_DIR', '$work/private' );
define( 'WP_HOME', 'http://127.0.0.1:8088' );
define( 'WP_SITEURL', 'http://127.0.0.1:8088' );
define( 'DISABLE_WP_CRON', true );
// No outbound HTTP (update checks would stall the single-threaded server).
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CONFIG

# Outgoing mail is written to files the scripts read (no SMTP in tests).
cat > "$wp/wp-content/mu-plugins/e2e-mail-capture.php" <<MAIL
<?php
add_filter( 'pre_wp_mail', static function ( \$short, array \$atts ) {
	file_put_contents( '$work/mails/' . gmdate( 'His' ) . '-' . substr( md5( wp_json_encode( \$atts ) . microtime() ), 0, 6 ) . '.json', wp_json_encode( \$atts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	return true;
}, 10, 2 );
MAIL

# No update checks: they need WordPress.org, which a sandbox may not reach.
cat > "$wp/wp-content/mu-plugins/e2e-no-updates.php" <<'NOUPDATES'
<?php
add_action(
	'init',
	static function () {
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
	}
);
NOUPDATES

php "$here/install.php" "$wp"
php "$here/seed.php" "$wp"

DX_E2E_WP="$wp" nohup php -S 127.0.0.1:8088 -t "$wp" "$here/router.php" > "$work/server.log" 2>&1 &
for _ in $(seq 1 30); do
	curl -s -o /dev/null http://127.0.0.1:8088/ && exit 0
	sleep 0.5
done
echo "server did not start" >&2
exit 1

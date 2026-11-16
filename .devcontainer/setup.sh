#!/usr/bin/env bash
# Prepares the development site: WP-CLI, WordPress, the plugin and a few demo shares.
# Safe to run again.
#
# Runs automatically when the dev container starts. Without VS Code:
#   docker compose -f .devcontainer/docker-compose.yml up -d
#   docker compose -f .devcontainer/docker-compose.yml exec wordpress bash /workspace/.devcontainer/setup.sh
set -euo pipefail

WP_ROOT=/var/www/html
SITE_URL="http://localhost:${WP_PORT:-8070}"

if [ ! -x /usr/local/bin/wp ]; then
	echo "Installing WP-CLI..."
	curl -fsSL -o /usr/local/bin/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	# Run WP-CLI as www-data so the files it creates stay writable for Apache.
	printf '#!/bin/sh\nexec runuser -u www-data -- php /usr/local/bin/wp-cli.phar --path=%s "$@"\n' "$WP_ROOT" >/usr/local/bin/wp
	chmod +x /usr/local/bin/wp
fi

echo "Waiting for the WordPress files and the database..."
for _ in $(seq 1 60); do
	[ -f "$WP_ROOT/wp-config.php" ] && [ -f "$WP_ROOT/wp-includes/version.php" ] && wp db check >/dev/null 2>&1 && break
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title="Nemvo" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	wp option update timezone_string Europe/Stockholm
	wp option update blogdescription "Secure sharing, the WordPress way"
	wp rewrite structure '/%postname%/'
fi

wp plugin activate nemvo-share
wp eval-file /workspace/.devcontainer/demo-content.php
wp rewrite flush

cat <<EOF

Ready.
  Site      $SITE_URL
  Shares    $SITE_URL/wp-admin/admin.php?page=nemvo-share   (admin / admin)
  Settings  $SITE_URL/wp-admin/admin.php?page=nemvo-share-settings
  Mailpit   http://localhost:${MAILPIT_PORT:-8075}
EOF
wp nemvo-share list --fields=id,title,status,url,password

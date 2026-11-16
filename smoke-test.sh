#!/usr/bin/env bash
# Installs a built zip on a brand-new WordPress and checks that it works out of the box:
# wp-admin screens and the share page load without PHP errors, a share with a password and
# a file can be unlocked and downloaded intact (also in ranges), stored files are encrypted
# and refused by the web server, burn after reading destroys the share, an upload goes
# through the chunked REST API (and refuses anonymous callers with 401 before checking
# parameters, without listing its routes in the REST index), uninstall cleans up, and
# Plugin Check reports no errors or warnings. Also lints on PHP 7.4.
# Usage: ./smoke-test.sh dist/nemvo-share-x.y.z.zip
set -euo pipefail
cd "$(dirname "$0")"

zip_file=${1:?usage: $0 dist/nemvo-share-x.y.z.zip}
project=nemvo-share-smoke
port=${SMOKE_PORT:-8077}
url="http://localhost:$port"
work=$(mktemp -d)
trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

fail=0
check() { # name, url, [cookie jar], [expected status]
	local body status
	body=$(curl -s -w '\n%{http_code}' ${3:+-b "$3"} "$2")
	status=${body##*$'\n'}
	if [ "$status" != "${4:-200}" ] || grep -qiE '(fatal error|warning|notice|deprecated)</b>:|critical error' <<<"$body"; then
		echo "FAIL $1 ($status)"; fail=1
	else
		echo "ok   $1"
	fi
}
expect() { # name, condition result ("1" = pass)
	if [ "$2" = 1 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}

echo "== PHP 7.4 lint"
lint=$(docker run --rm -v "$PWD/nemvo-share":/src -w /src php:7.4-cli sh -c 'find . -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors" || true')
if [ -n "$lint" ]; then echo "$lint"; echo "FAIL php 7.4 lint"; fail=1; else echo "ok   php 7.4 lint"; fi

cp "$zip_file" "$work/plugin.zip"
cat >"$work/compose.yml" <<YAML
services:
  db:
    image: mariadb:11.4
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wordpress:
    image: wordpress:php8.3-apache
    depends_on: { db: { condition: service_healthy } }
    ports: ["$port:80"]
    environment: { WORDPRESS_DB_HOST: db, WORDPRESS_DB_USER: wp, WORDPRESS_DB_PASSWORD: wp, WORDPRESS_DB_NAME: wp, WORDPRESS_DEBUG: "1" }
    volumes: ["$work/plugin.zip:/tmp/plugin.zip:ro"]
YAML

echo "== Starting a clean WordPress"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp core is-installed >/dev/null 2>&1 && break; wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null

echo "== Installing $zip_file"
wp plugin install /tmp/plugin.zip --activate
expect "share page created on activation" "$([ "$(wp post list --post_type=page --name=share --format=count)" = 1 ] && echo 1)"

jar="$work/cookies"
curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$url/wp-login.php"
for page in nemvo-share nemvo-share-new nemvo-share-settings; do
	check "admin $page (empty)" "$url/wp-admin/admin.php?page=$page" "$jar"
done
check "share page without a link" "$url/share/"
check "unknown link answers 404" "$url/share/AAAAAAAAAAAAAAAAAAAAAAAA/" "" 404

echo "== A share with a password and a 5 MB file"
dc bash -c 'head -c 5242881 /dev/urandom > /tmp/smoke.bin && chmod 644 /tmp/smoke.bin'
out=$(wp nemvo-share create --title="Smoke share" --note="Smoke <note>" --file=/tmp/smoke.bin --password=auto --expires=1d)
link=$(sed -n 's/^Link: *//p' <<<"$out")
password=$(sed -n 's/^Password: *//p' <<<"$out")
expect "link looks like /share/<secret>/ ($link)" "$(grep -qE '/share/[A-Za-z0-9]{24}/$' <<<"$link" && echo 1)"
check "admin list with a share" "$url/wp-admin/admin.php?page=nemvo-share" "$jar"
check "admin edit screen" "$url/wp-admin/admin.php?page=nemvo-share&action=edit&share=1" "$jar"
check "site health" "$url/wp-admin/site-health.php" "$jar"

visitor="$work/visitor"
page=$(curl -s -c "$visitor" -b "$visitor" -D "$work/headers" "$link")
expect "password form shown, note hidden" "$(grep -q 'nemvo_share_password' <<<"$page" && ! grep -q 'Smoke &lt;note&gt;' <<<"$page" && echo 1)"
expect "title hidden before the password" "$(! grep -q 'Smoke share' <<<"$page" && grep -q 'Protected share' <<<"$page" && echo 1)"
expect "share page is not cached or indexed" "$(grep -qi '^cache-control: no-store' "$work/headers" && grep -qi '^x-robots-tag: noindex' "$work/headers" && grep -qi '^referrer-policy: no-referrer' "$work/headers" && echo 1)"
nonce=$(grep -o 'name="nemvo_share_nonce" value="[^"]*"' <<<"$page" | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -c "$visitor" -b "$visitor" -d "nemvo_share_action=unlock&nemvo_share_nonce=$nonce" --data-urlencode "nemvo_share_password=$password" "$link")
expect "right password redirects ($status)" "$([ "$status" = 303 ] && echo 1)"
page=$(curl -s -b "$visitor" "$link")
expect "note shown and escaped" "$(grep -q 'Smoke &lt;note&gt;' <<<"$page" && echo 1)"
expect "title shown after the password" "$(grep -q 'Smoke share' <<<"$page" && echo 1)"
download=$(grep -o 'href="[^"]*/download/[0-9]*/"' <<<"$page" | head -1 | sed 's/href="//;s/"$//')
curl -s -b "$visitor" -o "$work/got.bin" "$download"
want=$(dc sha256sum /tmp/smoke.bin | cut -d' ' -f1)
got=$(shasum -a 256 "$work/got.bin" | cut -d' ' -f1)
expect "download matches the original" "$([ "$want" = "$got" ] && echo 1)"
range=$(curl -s -b "$visitor" -H 'Range: bytes=262140-262150' -o /dev/null -w '%{http_code} %{size_download}' "$download")
expect "range request ($range)" "$([ "$range" = '206 11' ] && echo 1)"
status=$(curl -s -o "$work/nopw" -w '%{http_code}' "$download")
expect "download without the password refused ($status)" "$([ "$status" = 403 ] && grep -q 'nemvo_share_password' "$work/nopw" && ! grep -q 'Smoke share' "$work/nopw" && echo 1)"

stored=$(dc bash -c 'ls /var/www/html/wp-content/uploads/nemvo-share-*/files/*.bin | head -1')
expect "stored file is encrypted" "$(dc cmp -s "$stored" /tmp/smoke.bin && echo 0 || echo 1)"
direct=${stored#/var/www/html}
status=$(curl -s -o /dev/null -w '%{http_code}' "$url$direct")
expect "web server refuses the stored file ($status)" "$([ "$status" = 403 ] && echo 1)"

echo "== Burn after reading"
burn=$(wp nemvo-share create --title=Burn --note=once --burn --porcelain)
page=$(curl -s -c "$work/b" -b "$work/b" "$burn")
expect "burn share asks before opening" "$(grep -q 'value="open"' <<<"$page" && ! grep -q '>once<' <<<"$page" && echo 1)"
nonce=$(grep -o 'name="nemvo_share_nonce" value="[^"]*"' <<<"$page" | sed 's/.*value="//;s/"$//')
page=$(curl -s -c "$work/b" -b "$work/b" -d "nemvo_share_action=open&nemvo_share_nonce=$nonce" "$burn")
expect "note shown once" "$(grep -q 'nvs-note-text">once' <<<"$page" && echo 1)"
check "afterwards the link answers 410" "$burn" "" 410

echo "== Chunked upload through the REST API"
rest_nonce=$(curl -s -b "$jar" "$url/wp-admin/admin-ajax.php?action=rest-nonce")
head -c 300000 /dev/urandom >"$work/up.bin"
start=$(curl -s -b "$jar" -H "X-WP-Nonce: $rest_nonce" -H 'Content-Type: application/json' --data '{"name":"up.bin","size":300000,"type":"application/octet-stream"}' "$url/wp-json/nemvo-share/v1/uploads")
upload_id=$(sed -n 's/.*"id":"\([a-f0-9]*\)".*/\1/p' <<<"$start")
chunk=$(curl -s -b "$jar" -H "X-WP-Nonce: $rest_nonce" -F index=0 -F "chunk=@$work/up.bin" "$url/wp-json/nemvo-share/v1/uploads/$upload_id")
expect "upload accepted: $chunk" "$(grep -q '"complete":true' <<<"$chunk" && echo 1)"
anon=""
for req in 'POST /uploads {"name":"x","size":1}' 'POST /uploads {}' 'POST /uploads {"size":"abc"}' "POST /uploads/$upload_id {\"index\":0}" "POST /uploads/$upload_id {}" "DELETE /uploads/$upload_id {}" 'POST /uploads/0123456789abcdef0123456789abcdef {"index":"x"}'; do
	read -r method route body <<<"$req"
	status=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" -H 'Content-Type: application/json' --data "$body" "$url/wp-json/nemvo-share/v1$route")
	anon="$anon $method$route=$status"
	[ "$status" = 401 ] || anon="$anon(!)"
done
expect "anonymous REST requests get 401, with or without parameters:$anon" "$(grep -q '(!)' <<<"$anon" || echo 1)"
index=$(curl -s "$url/wp-json/")
expect "upload routes not in the REST index" "$(grep -q 'nemvo-share\\/v1\\/uploads' <<<"$index" || echo 1)"

echo "== Plugin Check"
wp plugin install plugin-check --activate >/dev/null 2>&1
pcp=$(wp plugin check nemvo-share --format=csv 2>&1 || true)
if grep -q 'No errors found' <<<"$pcp"; then
	echo "ok   plugin check (no errors or warnings)"
else
	echo "$pcp"; echo "FAIL plugin check"; fail=1
fi

echo "== Uninstall"
wp eval 'update_option( "nemvo_share_settings", array_merge( get_option( "nemvo_share_settings", array() ), array( "delete_on_uninstall" => 1 ) ) );'
wp plugin deactivate nemvo-share >/dev/null && wp plugin uninstall nemvo-share >/dev/null
left=$(wp eval 'global $wpdb; echo count( $wpdb->get_col( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_share%\"" ) ) + count( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nemvo_share%\"" ) );')
expect "uninstall removes tables and options ($left left)" "$([ "$left" = 0 ] && echo 1)"
expect "uninstall removes the stored files" "$(dc bash -c 'ls -d /var/www/html/wp-content/uploads/nemvo-share-* 2>/dev/null | wc -l' | grep -q '^0$' && echo 1)"

[ $fail = 0 ] && echo "SMOKE TEST PASSED" || { echo "SMOKE TEST FAILED"; exit 1; }

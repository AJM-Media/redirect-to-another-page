#!/usr/bin/env bash
set -uo pipefail

PHP_VERSION=${PHP_VERSION:-8.3}
WP_VERSION=${WP_VERSION:-latest}
SERVER=${SERVER:-apache}
INSTALL=${INSTALL:-root}

DIR=$(cd "$(dirname "$0")" && pwd)
REPO=$(dirname "$DIR")
WORK=$(mktemp -d)
SLUG=redirect-to-another-page
EXT=https://example.org

PASSES=0
FAILS=0
RESULTS=()

die() { echo "SETUP FAILED: $*"; exit 1; }
pass() { PASSES=$((PASSES + 1)); RESULTS+=("PASS $1"); echo "PASS  $1"; }
fail() { FAILS=$((FAILS + 1)); RESULTS+=("FAIL $1: $2"); echo "FAIL  $1"; echo "      $2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected '$2', got '$3'"; fi; }
expect_has() { if grep -qF -- "$2" <<<"$3"; then pass "$1"; else fail "$1" "missing '$2' in '$3'"; fi; }
expect_not() { if grep -qF -- "$2" <<<"$3"; then fail "$1" "found '$2'"; else pass "$1"; fi; }

BASE=""
[ "$INSTALL" = subfolder ] && BASE=/sub
SITE_URL=http://site.test$BASE
WPDIR=/var/www/html$BASE

if [ "$SERVER" = apache ]; then
	IMAGE=wordpress:php$PHP_VERSION-apache
	PHPC=site
else
	IMAGE=wordpress:php$PHP_VERSION-fpm
	PHPC=php
fi

x() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli "$PHPC" "$@"; }
wp() { x php -d memory_limit=1G /usr/local/bin/wp --path="$WPDIR" "$@"; }
get() { docker exec "$PHPC" curl -s "$@"; }
status_of() { get -o /dev/null -w '%{http_code} %{redirect_url}' "$1"; }
code_of() { get -o /dev/null -w '%{http_code}' "$1"; }

echo "== PHP $PHP_VERSION, WordPress $WP_VERSION, $SERVER, $INSTALL"

git -C "$REPO" archive --prefix="$SLUG/" HEAD | tar -x -C "$WORK" || die "git archive"
for private in tests .github .wordpress-org listing-images README.md; do
	if [ -e "$WORK/$SLUG/$private" ]; then fail "release zip leaves out $private" "present"; else pass "release zip leaves out $private"; fi
done

mkdir -p "$WORK/nginx" "$WORK/html" && chmod 777 "$WORK/html"
docker network create rtap >/dev/null || die "network"
docker run -d --name db --network rtap -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:10.11 >/dev/null || die "db"

if [ "$SERVER" = apache ]; then
	docker run -d --name site --network rtap --network-alias site.test -v "$WORK/html:/var/www/html" \
		--entrypoint docker-php-entrypoint "$IMAGE" apache2-foreground >/dev/null || die "site"
else
	docker run -d --name php --network rtap -v "$WORK/html:/var/www/html" \
		--entrypoint docker-php-entrypoint "$IMAGE" php-fpm >/dev/null || die "php"
	sed "s#__BASE__#$BASE#g" "$DIR/conf/nginx-site.conf" > "$WORK/nginx/default.conf"
	[ "$INSTALL" = multisite ] && sed -i "/#MULTISITE/r $DIR/conf/nginx-multisite.conf" "$WORK/nginx/default.conf"
	docker run -d --name web --network rtap --network-alias site.test -v "$WORK/html:/var/www/html" \
		-v "$WORK/nginx:/etc/nginx/conf.d" nginx:1.27-alpine >/dev/null || die "web"
fi

docker exec "$PHPC" sh -c 'curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp' || die "wp-cli"
x mkdir -p "$WPDIR"
WP_TARBALL=https://wordpress.org/wordpress-$WP_VERSION.tar.gz
[ "$WP_VERSION" = latest ] && WP_TARBALL=https://wordpress.org/latest.tar.gz
x sh -c "curl -sSfL --retry 5 --retry-all-errors -o /tmp/wordpress.tar.gz $WP_TARBALL && tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C $WPDIR" || die "core download"
echo "WordPress $(wp core version)"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --quiet || die "config"
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_LOG true --raw --quiet
wp config set WP_DEBUG_DISPLAY false --raw --quiet
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --quiet
wp config set DISABLE_WP_CRON true --raw --quiet

for i in $(seq 1 30); do
	if [ "$INSTALL" = multisite ]; then
		wp core multisite-install --url=http://site.test --title=RTAP --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	else
		wp core install --url="$SITE_URL" --title=RTAP --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	fi
	sleep 2
done
wp core is-installed --network 2>/dev/null || wp core is-installed || die "install"

if [ "$SERVER" = apache ]; then
	if [ "$INSTALL" = multisite ]; then
		cp "$DIR/conf/htaccess-multisite" "$WORK/htaccess"
	else
		sed "s#__BASE__#$BASE#g" "$DIR/conf/htaccess-single" > "$WORK/htaccess"
	fi
	docker cp "$WORK/htaccess" "$PHPC:$WPDIR/.htaccess" || die "htaccess"
fi

docker cp "$WORK/$SLUG" "$PHPC:$WPDIR/wp-content/plugins/" || die "copy plugin"
docker cp "$DIR/helpers/rtap.php" "$PHPC:/tmp/rtap.php" || die "copy helper"
docker exec "$PHPC" chown -R www-data:www-data "$WPDIR/wp-content/plugins/$SLUG"

if [ "$INSTALL" = multisite ]; then
	wp site create --slug=two --quiet || die "site two"
	wp plugin activate "$SLUG" --network --quiet || die "activate"
	TARGET=http://site.test/two
else
	wp plugin activate "$SLUG" --quiet || die "activate"
	TARGET=$SITE_URL
fi

t() { wp --url="$TARGET" eval-file /tmp/rtap.php "$@" | tail -n 1; }
wp --url="$TARGET" rewrite structure '/%postname%/' --hard --quiet 2>/dev/null || wp --url="$TARGET" rewrite structure '/%postname%/' --quiet
wp --url="$TARGET" rewrite flush --quiet

A=$(t post "Page A"); B=$(t post "Page B"); C=$(t post "Page C"); D=$(t post "Page D")
LOOP1=$(t post "Loop One"); LOOP2=$(t post "Loop Two"); PLAIN=$(t post "Plain Page")
OLD1=$(t post "Legacy One"); OLD2=$(t post "Legacy Two"); OLD3=$(t post "Legacy Bad")
t user editor1 editor >/dev/null
t user contrib1 contributor >/dev/null
URL_A=$(t link "$A"); URL_B=$(t link "$B"); URL_C=$(t link "$C"); URL_D=$(t link "$D")
echo "pages: A $URL_A, B $URL_B"
expect "pretty permalinks" "$TARGET/page-a/" "$URL_A"
expect "plain page loads" "200" "$(code_of "$TARGET/plain-page/")"

expect "admin saves a 301 to an external site" "saved:$EXT/target" "$(t save-as admin "$A" url=$EXT/target type=301)"
expect "301 redirect" "301 $EXT/target" "$(status_of "$URL_A")"
expect_has "redirect names the plugin" "X-Redirect-By: Redirect to Another Page" "$(get -sI "$URL_A")"
expect "editor saves a 302 to a path" "saved:/plain-page/" "$(t save-as editor1 "$B" url=/plain-page/ type=302)"
expect "302 to a path on this site" "302 $TARGET/plain-page/" "$(status_of "$URL_B")"
t save-as admin "$C" url=$EXT/three type=307 >/dev/null
expect "307 redirect" "307 $EXT/three" "$(status_of "$URL_C")"
t save-as admin "$D" url=$EXT/bad-type type=999 >/dev/null
expect "unknown type falls back to 301" "301 $EXT/bad-type" "$(status_of "$URL_D")"

expect "contributor can't set a redirect" "none" "$(t save-as contrib1 "$PLAIN" url=$EXT/x)"
expect "save without a valid nonce is refused" "refused" "$(t save-no-nonce "$PLAIN")"
expect "javascript: URL rejected" "error:invalid" "$(t save-as admin "$PLAIN" url='javascript:alert(1)')"
expect "protocol-relative URL rejected" "error:invalid" "$(t save-as admin "$PLAIN" url=//evil.example/x)"
expect "bare word rejected" "error:invalid" "$(t save-as admin "$PLAIN" url=about-us)"
expect "markup in URL rejected" "error:invalid" "$(t save-as admin "$PLAIN" url="$EXT/\"><script>alert(1)</script>")"
expect "redirect to itself rejected" "error:self" "$(t save-as admin "$PLAIN" url="$TARGET/plain-page/")"
expect "plain page still loads" "200" "$(code_of "$TARGET/plain-page/")"

t save-as admin "$LOOP1" url=/loop-two/ >/dev/null
t save-as admin "$LOOP2" url=/loop-one/ >/dev/null
expect "loop: first page not redirected" "200" "$(code_of "$TARGET/loop-one/")"
expect "loop: second page not redirected" "200" "$(code_of "$TARGET/loop-two/")"

expect "preview parameter ignored for visitors" "301 $EXT/target" "$(status_of "$URL_A?rtap_preview=1")"

expect "links unchanged by default" "$URL_A" "$(t link "$A")"
t save-as admin "$A" url=$EXT/target type=301 direct=1 new_tab=1 nofollow=1 >/dev/null
expect "direct link: permalink points at the target" "$EXT/target" "$(t link "$A")"
MENU=$(t menu "$A")
expect_has "menu: href is the target" "href=\"$EXT/target\"" "$MENU"
expect_has "menu: opens in a new tab" 'target="_blank"' "$MENU"
expect_has "menu: rel has noopener and nofollow" 'noopener nofollow' "$MENU"
NAV=$(t navblock "$A")
if [ "$NAV" = skip ]; then
	pass "navigation block (skipped: no HTML API in this WordPress)"
else
	expect_has "navigation block: new tab" 'target="_blank"' "$NAV"
	expect_has "navigation block: nofollow" 'nofollow' "$NAV"
fi
expect "clearing the URL removes the redirect" "none" "$(t save-as admin "$D" url=)"
expect "cleared page loads" "200" "$(code_of "$URL_D")"

expect "path: home page can't be redirected" "[]" "$(t clean-from /)"
expect "path: other domains rejected" "[]" "$(t clean-from https://other.example/x)"
expect "path: added" "${TARGET#http://site.test}/old-page/" "$(t add-path /old-page/ /page-b/ 302)"
expect "path redirect" "302 $TARGET/page-b/" "$(status_of "$TARGET/old-page/")"
expect "path redirect ignores case and trailing slash" "302 $TARGET/page-b/" "$(status_of "$TARGET/OLD-PAGE")"
t add-path "/campaign/?src=card" "$EXT/landing" 301 >/dev/null
expect "path with query matches" "301 $EXT/landing" "$(status_of "$TARGET/campaign/?src=card")"
expect "path with query needs the query" "404" "$(code_of "$TARGET/campaign/?src=other")"

SITEMAP=$(get -L "$TARGET/wp-sitemap-posts-page-1.xml")
expect_has "sitemap lists normal pages" "/plain-page/" "$SITEMAP"
expect_not "sitemap leaves out redirected pages" "/page-c/" "$SITEMAP"

t qppr "$OLD1" active=1 url=$EXT/legacy type=meta newwindow=_blank relnofollow=1 >/dev/null
t qppr "$OLD2" active=1 url=/plain-page/ type=307 rewritelink=1 >/dev/null
t qppr "$OLD3" active=1 url='javascript:alert(1)' type=301 >/dev/null
t qppr-paths "/qppr-old/|$EXT/quick" "/qppr-bad/|$EXT/\"><script>alert(1)</script>" >/dev/null
expect "legacy: found before import" "pending 2 1" "$(t scan)"
expect "legacy: meta type honoured as 302" "302 $EXT/legacy" "$(status_of "$TARGET/legacy-one/")"
expect "legacy: 307 to a path honoured" "307 $TARGET/plain-page/" "$(status_of "$TARGET/legacy-two/")"
expect "legacy: rewrite link honoured" "$TARGET/plain-page/" "$(t link "$OLD2")"
expect "legacy: javascript: URL never followed" "200" "$(code_of "$TARGET/legacy-bad/")"
expect "legacy: quick redirect honoured" "301 $EXT/quick" "$(status_of "$TARGET/qppr-old/")"
expect "legacy: unsafe quick redirect never followed" "404" "$(code_of "$TARGET/qppr-bad/")"
NOTICE=$(t notice)
expect_has "legacy: one import notice" "found 3 redirects from Quick Page/Post Redirect" "$NOTICE"
expect "admin screens render, no unescaped markup" "ok" "$(t admin-check "$OLD1")"

x mkdir -p "$WPDIR/wp-content/plugins/quick-pagepost-redirect-plugin"
x sh -c "printf '<?php\n/*\nPlugin Name: QPPR stand-in\n*/\n' > $WPDIR/wp-content/plugins/quick-pagepost-redirect-plugin/page_post_redirect_plugin.php"
wp --url="$TARGET" plugin activate quick-pagepost-redirect-plugin --quiet
expect "legacy: left to the old plugin while it's active" "200" "$(code_of "$TARGET/legacy-one/")"
wp --url="$TARGET" plugin deactivate quick-pagepost-redirect-plugin --quiet
expect "legacy: honoured again once it's deactivated" "302 $EXT/legacy" "$(status_of "$TARGET/legacy-one/")"

expect "import: 2 posts, 1 path, 2 skipped" "2 1 2" "$(t import)"
expect "import: stored as own redirect" "[$EXT/legacy]" "$(t meta "$OLD1" _rtap_url)"
expect "import: meta type stored as 302" "[302]" "$(t meta "$OLD1" _rtap_type)"
expect "import: new tab carried over" "[1]" "$(t meta "$OLD1" _rtap_new_tab)"
expect "import: old data left in place" "[$EXT/legacy]" "$(t meta "$OLD1" _pprredirect_url)"
expect "import: redirect still works" "302 $EXT/legacy" "$(status_of "$TARGET/legacy-one/")"
expect "import: quick redirect still works" "301 $EXT/quick" "$(status_of "$TARGET/qppr-old/")"
expect "import: notice gone" "none" "$(t notice)"
expect "import: nothing left to import" "imported 0 0" "$(t scan)"
expect "import: clearing an imported redirect sticks" "none" "$(t save-as admin "$OLD2" url=)"
expect "import: cleared page loads" "200" "$(code_of "$TARGET/legacy-two/")"

NOTICES=$(x sh -c "grep -h '$SLUG' '$WPDIR/wp-content/debug.log' 2>/dev/null" | sort -u | head -n 5)
expect "no PHP notices from the plugin" "" "$NOTICES"

wp user meta update 1 rtap_donate_dismissed 1 --quiet
if [ "$INSTALL" = multisite ]; then
	wp plugin deactivate "$SLUG" --network --quiet
else
	wp plugin deactivate "$SLUG" --quiet
fi
wp plugin uninstall "$SLUG" --quiet
if wp --url="$TARGET" option get rtap_path_redirects >/dev/null 2>&1; then fail "uninstall removes path redirects" "still there"; else pass "uninstall removes path redirects"; fi
expect "uninstall removes post meta" "" "$(wp --url="$TARGET" post meta get "$A" _rtap_url 2>/dev/null)"
expect "uninstall leaves the old plugin's data" "$EXT/legacy" "$(wp --url="$TARGET" post meta get "$OLD1" _pprredirect_url 2>/dev/null)"
expect "uninstall removes user meta" "" "$(wp user meta get 1 rtap_donate_dismissed 2>/dev/null)"

echo
echo "== $PASSES passed, $FAILS failed"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### PHP $PHP_VERSION, WordPress $WP_VERSION, $SERVER, $INSTALL: $PASSES passed, $FAILS failed"
		for r in "${RESULTS[@]}"; do
			case $r in FAIL*) echo "- :x: ${r#FAIL }" ;; esac
		done
	} >> "$GITHUB_STEP_SUMMARY"
fi
if [ "$FAILS" -gt 0 ]; then
	echo "== web server log (last 20 lines)"
	docker logs --tail 20 "$([ "$SERVER" = apache ] && echo site || echo web)" 2>&1
	exit 1
fi

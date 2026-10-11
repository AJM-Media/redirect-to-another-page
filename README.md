# Redirect to Another Page

[![Tests](https://github.com/AJM-Media/redirect-to-another-page/actions/workflows/tests.yml/badge.svg)](https://github.com/AJM-Media/redirect-to-another-page/actions/workflows/tests.yml)

WordPress plugin that redirects any page or post to another URL from its edit
screen, and keeps old addresses working.

- A Redirect box on every page, post and public custom post type: target URL,
  301/302/307, new tab and nofollow for menu links, and an option to point the
  page's links straight at the target.
- Redirects for old addresses with no page behind them (Tools > Redirects).
- Loop protection, capability checks and nonces on every change, and only
  http(s) URLs or site paths accepted.
- Imports redirects from Quick Page/Post Redirect, and follows them until
  they're imported.

The wp.org listing text is in [`readme.txt`](readme.txt).

## Development

The repo root is the plugin folder. Build the release zip from the committed
code; files marked `export-ignore` in `.gitattributes` are left out:

    git archive --format=zip --prefix=redirect-to-another-page/ -o ../redirect-to-another-page.zip HEAD

wp.org listing images (icon, banner, screenshots) are in `.wordpress-org/`.
The icon and banner are rendered from the HTML in `listing-images/`.

Tests run in GitHub Actions on every branch push (`tests/run.sh`): WordPress in
Docker across PHP 7.4-8.3, WordPress 6.0 and latest, Apache and nginx, and
root, subfolder and multisite installs.

## Releasing

1. Bump the version in the plugin header, `RTAP_VERSION` and the readme's
   `Stable tag`, and add a changelog entry.
2. Push the commit, then push a tag with the bare version number, e.g.
   `git tag 1.0.1 && git push origin 1.0.1`.

The tag runs the tests, checks the three version numbers match, then
publishes to WordPress.org SVN (`trunk`, `tags/<version>` and the listing
images). It needs the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets.

Filter: `rtap_post_types( string[] $post_types )`.

## Support

Found a bug or have an idea? [Open an issue](https://github.com/AJM-Media/redirect-to-another-page/issues).

The plugin is free. If it saves you time, you can
[buy me a coffee](https://ko-fi.com/ajmmedia).

## Licence

GPLv2 or later.

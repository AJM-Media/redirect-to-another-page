=== Redirect to Another Page ===
Contributors: ajmmedia
Donate link: https://ko-fi.com/ajmmedia
Tags: redirect, 301 redirect, page redirect, menu, links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redirect any page or post to another URL from its edit screen, and keep old addresses working.

== Description ==

Need a page to send visitors somewhere else? Open it, paste the new address into the Redirect box, and update. No server rules, no regular expressions.

= On every page and post =

* **Redirect to any address**: another page on your site, or any other website.
* **Choose the type**: 301 (moved permanently), 302 or 307 (temporary).
* **Link straight to the new address**: menus and post lists can point at the new address, so visitors skip the redirect.
* **New tab and nofollow** for menu links, in classic menus and the Navigation block.
* **View the page without redirecting** while you're logged in, so you can still check and edit it.

= Redirects for old addresses =

Under Tools > Redirects, send an old address on your site, such as `/old-page/`, to a new one. The old address doesn't need a page.

= Safe by design =

* Redirect loops are caught: a redirect that would lead back to where it started is switched off, and the edit screen tells you why.
* Only users who can publish a page can redirect it. Contributors can't.
* Redirected pages are left out of the WordPress sitemap.
* Every address is checked before it's saved, and only web addresses (https and http) or paths on your site are accepted.
* No settings to configure, no tracking, no external requests.

= Switching from another plugin =

It imports redirects from Quick Page/Post Redirect, and follows them until you import, so nothing breaks while you switch. See the FAQ.

= Contributing =

Source code and issue tracker: [github.com/AJM-Media/redirect-to-another-page](https://github.com/AJM-Media/redirect-to-another-page)

= Privacy =

The plugin collects no data, makes no external requests and sends nothing to the plugin author or any third-party service.

== Installation ==

1. Install and activate the plugin.
2. Edit a page or post and find the Redirect box in the sidebar.
3. Enter the new address, choose the type and update the page.
4. For old addresses without a page, go to Tools > Redirects.

== Frequently Asked Questions ==

= Moving from Quick Page/Post Redirect =

That plugin is no longer available from WordPress.org. To move:

1. Install and activate Redirect to Another Page.
2. Deactivate the old plugin. From then on, this plugin follows the page and post redirects and quick redirects it saved, with the same addresses and types.
3. Go to Tools > Redirects and click "Import redirects". They become this plugin's own redirects, and you can edit them on each page's edit screen.
4. Delete the old plugin.

Redirects of the old "meta" type become 302 redirects. "Open in a new window", "nofollow" and "show redirect URL" carry over as "Open menu links in a new tab", "Add nofollow to menu links" and "Link straight to the new address". Global override settings are not imported. The import doesn't change or delete the old plugin's data.

= How is this different from Page Links To? =

Page Links To is a good, long-running plugin, and if it does what you need, keep using it. It always points a page's links at the new address and redirects with a 301. Redirect to Another Page lets you choose 301, 302 or 307 for each page, leave the page's links alone or point them at the new address, add nofollow to menu links, and redirect old addresses that have no page.

= Does the page need to be published? =

Yes. Drafts and scheduled pages aren't public, so there's nothing to redirect yet.

= How do I see a page that redirects? =

While logged in, use "View this page without redirecting" in the Redirect box, or add `?rtap_preview=1` to the page's address.

= I changed a 301 redirect and my browser still goes to the old address =

Browsers remember 301 redirects. Test in a private window, or use 302 while you're trying things out and switch to 301 when you're happy.

= Can I redirect the home page? =

Not from Tools > Redirects. If your home page is a static page, you can redirect it from its edit screen.

= Does it support wildcards or regular expressions? =

No. It's built for one address at a time. For pattern-based redirects, use a full redirect manager.

= Which post types does it work with? =

Pages, posts and every public custom post type. Developers can change the list with the `rtap_post_types` filter.

== Screenshots ==

1. The Redirect box on a page's edit screen.
2. Tools > Redirects: redirects for old addresses and the list of redirected pages.

== Changelog ==

= 1.0.0 =
* First release.

=== News SEO Helper ===
Contributors: ielko
Tags: news, seo, open graph, structured data, sitemap
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO for news publishers: news sitemap, NewsArticle structured data, Open Graph tags, category search descriptions, and image metadata cleanup.

== Description ==

News SEO Helper prepares a WordPress news site for news aggregators and social sharing. It fills the gaps a general SEO plugin leaves for publishers, and works either on its own or alongside Yoast SEO, Rank Math, or All in One SEO.

**What it does**

* **News sitemap** at `/news-sitemap.xml`, listing recent posts in the format news aggregators expect
* **NewsArticle JSON-LD** structured data on posts, WebPage on pages, with publisher and image data
* **Open Graph and Twitter Card tags** so articles shared to Facebook, X, and messaging apps show the right title, description, and image
* **Search descriptions for categories and tags** — without one, search engines write their own snippet from whatever text appears first on the archive, which is often a banner alt text repeated across every category
* **Per-post SEO fields** for title, description, and noindex/nofollow, on posts and pages
* **Removal of AI and provenance metadata from images** (see below)
* **Redirect manager** for moved or retired URLs
* **robots.txt editor** with checks for rules that would block news crawlers
* **Preview dashboard** showing how recent posts appear in search results, with a tag tester

**Removing AI / provenance metadata from images**

Photoshop and similar tools embed C2PA "Content Credentials" and XMP provenance data inside image files. Some platforms read that data when a link is shared, which can affect how — or whether — the image is displayed in the post preview, and can attach an AI-related label to the post.

When enabled, this plugin removes that data from images as they are uploaded. Only the metadata containers are rewritten: JPEG APP segments, WebP RIFF chunks, and PNG ancillary chunks. The compressed image data is copied across unchanged, so there is no re-encoding and no loss of quality, and ICC colour profiles are preserved. A batched tool is included for images already in the media library.

This setting is off by default.

**Working alongside other SEO plugins**

If Yoast SEO, Rank Math, or All in One SEO is active, the plugin passes its values through their filters instead of printing duplicate tags. With no such plugin installed, it outputs the tags itself.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/news-seo-helper`, or install it through **Plugins → Add New**.
2. Activate it through the **Plugins** menu.
3. Visit **News SEO** in the admin menu to set your homepage description and review the dashboard.
4. Optionally visit **News SEO → Category descriptions** to write a search description for each category.
5. Optionally visit **News SEO → Image metadata** to turn on metadata removal for new uploads.

== Frequently Asked Questions ==

= Do I need another SEO plugin? =

No. News SEO Helper can output meta descriptions, Open Graph, and structured data on its own. If you already use Yoast SEO, Rank Math, or All in One SEO, it defers to them and fills in what they do not cover.

= Why do all my category pages show the same text in search results? =

Because they have no meta description. Search engines then build a snippet from the first text they find on the page, which is frequently a banner image's alt text or a menu label — identical on every category. Set a description under **News SEO → Category descriptions**.

= How long until changes appear in search results? =

Search engines re-crawl on their own schedule, so expect days to a few weeks. They may also choose to show their own text instead of your description for some searches.

= Does removing image metadata reduce image quality? =

No. Only metadata containers are removed; the compressed image data is copied byte for byte, so the picture is unchanged. ICC colour profiles are kept.

= Can I undo the image metadata cleanup? =

No. The bulk cleaner rewrites files in the media library in place. Back up `wp-content/uploads` before running it. The upload-time setting only affects new uploads and leaves existing files alone.

= Where is the news sitemap? =

At `/news-sitemap.xml` on your site. Submit that URL in your search engine's webmaster tools.

== Screenshots ==

1. Dashboard with search result previews of recent posts
2. Bulk editor for category search descriptions
3. Image metadata settings and library cleaner
4. Per-post SEO fields on the post editor

== Changelog ==

= 1.1.0 =
* First WordPress.org release, as News SEO Helper
* Added search descriptions for categories and tags, with a bulk editor
* Added removal of XMP/C2PA provenance metadata from images, with a library cleaner
* Removed a deprecated no-op class
* Hardened sanitization of server and request values

= 1.0.16 =
* Extended SEO fields and Open Graph output to pages

= 1.0.13 =
* Added robots.txt health screen and crawler checks

= 1.0.10 =
* Added homepage and per-post search snippets, and SEO plugin integration

== Upgrade Notice ==

= 1.1.0 =
First WordPress.org release. Adds category search descriptions and optional removal of AI/provenance metadata from images.

# WordPress.org submission — News SEO Helper

## Form fields

**Plugin Name:** `News SEO Helper`

**Plugin Slug (requested):** `news-seo-helper`  *(verified available)*

**Plugin ZIP:** `dist/news-seo-helper-1.1.0.zip`

---

## Description (paste into "Plugin Description" on the submit form)

News SEO Helper prepares a WordPress news site for Google News and social sharing.
It covers the publisher-specific gaps that general SEO plugins leave, and works either
standalone or alongside Yoast SEO, Rank Math, or All in One SEO.

Features:

* Google News sitemap at /news-sitemap.xml, listing recent posts in the format Google
  News expects.
* NewsArticle JSON-LD structured data on posts and WebPage on pages, including
  publisher and image data.
* Open Graph and Twitter Card tags so shared articles show the correct title,
  description, and image.
* Search descriptions for categories and tags, with a bulk editor. Without a
  description, search engines generate a snippet from whatever text appears first on
  the archive page, which is often a banner image's alt text repeated identically
  across every category.
* Per-post SEO fields for title, description, and noindex/nofollow, on posts and pages.
* Optional removal of XMP and C2PA provenance metadata from images. Image editing tools
  embed provenance data that some platforms read when a link is shared, which can affect
  whether the image appears in the preview and can attach an AI-related label. Only
  metadata containers are rewritten (JPEG APP segments, WebP RIFF chunks, PNG ancillary
  chunks); the compressed image data is copied unchanged, so there is no re-encoding or
  quality loss, and ICC colour profiles are preserved. Off by default, with a batched
  tool for images already in the media library.
* Redirect manager for moved or retired URLs.
* robots.txt editor with checks for rules that would block news crawlers.
* Preview dashboard showing how recent posts appear, including a tag tester.

The plugin detects Yoast SEO, Rank Math, and All in One SEO and passes its values
through their filters rather than printing duplicate tags.

All code is GPL-2.0-or-later. No external services are called at runtime, no data is
collected, and no third-party assets are bundled.

---

## Notes for the review team (optional — include if a message field is offered)

* This is a resubmission. The first attempt was rejected because the name began
  with a restricted term; it has been renamed from the original to "News SEO
  Helper", and neither the display name nor the slug now begins with a
  trademarked term.
* The plugin is also distributed from GitHub, where it self-updates. That code is
  **not present in this package** — the build omits it, so this copy updates only
  through WordPress.org. There is no updater class, and no reference to the update
  transients anywhere in the ZIP.
* The image metadata feature writes to files in the uploads directory. It is disabled by
  default, requires `upload_files` capability, is nonce-protected, and writes through a
  temporary file plus rename. Unparseable input is rejected rather than partially
  written.
* `$wpdb->get_col()` is used once, in `includes/class-image-metadata-admin.php`, to list
  attachment IDs. It takes no user input.

---

## Before you submit — checklist

- [ ] Log in at https://wordpress.org/plugins/developers/add/ with the WordPress.org
      account that should own the plugin.
- [ ] Confirm the `Contributors:` line in `readme.txt` matches that account's username.
      It currently reads `ielko` — change it if you submit under a different account,
      or the plugin page will not list you as an author.
- [ ] Upload `dist/news-seo-helper-1.1.0.zip`.
- [ ] Paste the description above.

## After approval

1. Set an SVN password (separate from your account password):
   https://profiles.wordpress.org/me/profile/edit/group/3/
2. Check out the repository:
   `svn co https://plugins.svn.wordpress.org/news-seo-helper ~/svn/news-seo-helper`
3. The directory page images are already in `.wordpress-org/` (icon, both banners,
   four screenshots) and svn-deploy.sh copies them to SVN assets/ automatically.
4. Deploy:
   `./bin/svn-deploy.sh ~/svn/news-seo-helper`
   Review the printed `svn status`, then commit as instructed.

Nothing appears publicly until that first SVN commit — approval alone does not publish
the plugin.

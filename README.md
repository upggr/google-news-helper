# Google News Helper

A WordPress plugin that fully optimizes your site for **Google News** indexing.

**Author:** Ioannis Kokkinis – [buy-it.gr](https://buy-it.gr/)
**GitHub:** [upggr/google-news-helper](https://github.com/upggr/google-news-helper)

---

## Features

- Adds **Open Graph** meta tags (`og:title`, `og:image`, `og:description`, `og:type=article`, …)
- Adds **Article Open Graph** tags (published time, modified time, author, section, tags)
- Adds **Twitter Card** tags (`summary_large_image`)
- Adds `news_keywords` meta tag (up to 10 post tags)
- Adds `robots` meta: `max-image-preview:large`
- Adds **NewsArticle JSON-LD** structured data (Schema.org)
- Uses the post's **featured image** as the Google News thumbnail automatically
- **Admin dashboard** with a live preview of how your last 5 posts appear on Google News
- **Conflict detection** – detects Yoast SEO, Rank Math, and All-in-One SEO and skips duplicate `og:` tags
- **Auto-updates** directly from GitHub releases — no manual uploads needed

---

## Installation

### From GitHub (manual)

1. Download the latest release ZIP from the [Releases page](https://github.com/upggr/google-news-helper/releases).
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

### Copy into wp-content/plugins

```bash
cd wp-content/plugins
git clone https://github.com/upggr/google-news-helper.git
```

Activate the plugin from **Plugins → Installed Plugins**.

---

## Auto-updates from GitHub

The plugin checks the GitHub releases/tags of this repository on every WordPress update check. When a new tag (e.g. `v1.1.0`) is pushed, WordPress will show the standard "Update available" notice in the Plugins screen and you can update with one click — exactly like a plugin from the official directory.

**How to publish a new release:**

1. Update `GNH_VERSION` in `google-news-helper.php` (e.g. `'1.1.0'`).
2. Commit and push.
3. Create a Git tag that matches the version:
   ```bash
   git tag v1.1.0
   git push origin v1.1.0
   ```
4. WordPress sites running the plugin will detect the new version on their next update check.

---

## Admin Dashboard

Navigate to **Google News** in the WordPress admin sidebar.

- **Enable toggle** – activates or deactivates all meta tag output site-wide.
- **Post previews** – shows the last 5 published posts as they would appear in Google News, including:
  - Thumbnail (featured image)
  - Headline (truncated to 110 chars, matching Google's NewsArticle spec)
  - Category, site name, and publication time
  - Status badge: green "Ready for Google News" or amber warning with details (e.g., missing featured image)

---

## Requirements

- WordPress 5.5+
- PHP 7.4+
- Posts must have a **featured image** for the best appearance in Google News

---

## Compatibility with SEO Plugins

If **Yoast SEO**, **Rank Math**, or **All-in-One SEO** is active, this plugin will skip its own `og:*` and Twitter Card output to avoid duplicate tags — those plugins already handle them. The **NewsArticle JSON-LD** block is always output because the SEO plugins typically emit a generic `Article` type, not `NewsArticle`.

---

## License

GPL-2.0-or-later

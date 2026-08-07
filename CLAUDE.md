# Google News Helper — working notes

WordPress plugin that handles Google News / SEO output for the sites we manage.
Repo: `upggr/google-news-helper`. Sites pull releases through the built-in GitHub updater.

## Golden rule: SEO changes go in this plugin

When a client asks for something about how their site appears in Google — snippets,
titles, descriptions, structured data, sitemaps, robots rules, redirects — the fix
belongs **here**, not in the theme, not in Elementor, not in a per-site snippet plugin,
and not as a one-off edit on the server.

Reasons this keeps mattering:

- The sites we manage have **no major SEO plugin** (no Yoast / Rank Math / AIOSEO).
  This plugin is the only thing writing `<meta name="description">`, Open Graph, and
  JSON-LD. If it doesn't emit a tag, the tag does not exist.
- Fixing one site by hand leaves every other site broken in the same way, and the fix
  is lost the next time the theme updates.
- Anything added here ships to every site through the updater and gets a UI the client
  can operate themselves — which is usually the actual request behind "can you change
  this text in Google".

Only edit a site directly for **content** (the words in a description, an image alt
text, a page's own SEO fields). Anything structural is a plugin change.

## Release process

The updater reads GitHub tags, so a change is not live until it is tagged.

1. Edit code.
2. Bump the version in **both** places in `google-news-helper.php` — the
   `Version:` header and `define( 'GNH_VERSION', ... )`. They must match or the
   update check misbehaves.
3. `./bin/lint.sh`
4. Commit, then `git tag vX.Y.Z && git push origin main --tags`.
5. On the site: Plugins → check for updates → update.

The updater picks the **highest semver tag**, so never tag out of order.

## Linting

`./bin/lint.sh` syntax-checks every PHP file. It uses local `php` when present and
otherwise falls back to linting over SSH:

```bash
GNH_LINT_HOST=root@89.167.92.241 ./bin/lint.sh
```

There is no test suite. Lint plus a manual check on the live site is the bar.

## Architecture

`google-news-helper.php` loads every class in `includes/` and instantiates them on
`plugins_loaded`. Admin-only classes are constructed inside an `is_admin()` guard.

| File | Responsibility |
|---|---|
| `class-settings.php` | Registers options (`gnh_enabled`, homepage description) |
| `class-post-seo.php` | SEO metabox on posts and pages; per-post title/desc/noindex |
| `class-term-seo.php` | Category/tag search descriptions — front-end output + term field |
| `class-term-seo-admin.php` | Bulk editor for all category descriptions |
| `class-meta-tags.php` | Homepage + singular meta, Open Graph, Twitter, JSON-LD |
| `class-robots.php` / `class-robots-admin.php` | robots.txt management and health checks |
| `class-redirects.php` | Redirect manager |
| `class-news-sitemap.php` | Google News sitemap |
| `class-ad-nosnippet.php` | Wraps ad markup in `data-nosnippet` |
| `class-crawler-logs.php` | Crawler hit logging |
| `class-updater.php` | GitHub release updater |

### The dual-path pattern

Every SEO output class writes the same value two ways, and new ones must too:

1. **Filters** (`wpseo_metadesc`, `rank_math/frontend/description`, `aioseo_description`)
   so our value wins if a major SEO plugin is ever installed.
2. **Direct `wp_head` output**, guarded by `has_major_seo_plugin()`, for the normal case
   where no such plugin exists.

Skipping path 2 is the easy mistake — it means the tag silently never renders on our
actual sites, because the filters have nothing to hook into.

`wp_head` priorities are deliberately low (2–4): Facebook reads the *first* `og:title`
in the document, so ours has to come before the theme's.

## Gotchas

- **Missing description ⇒ Google invents one.** It scrapes the first text on the page.
  On zantetimes.gr that was a banner ad's alt text, so every category shared one
  identical junk snippet. Always give archives a real description.
- **`data-nosnippet` on ad markup** stops ad text from being harvested into snippets;
  that is what `class-ad-nosnippet.php` is for.
- **Categories may not live under `/category/`.** zantetimes.gr serves them at the root
  (`/zakinthos/`). Never hardcode the prefix; use `get_term_link()`.
- **Pages are not categories.** A "category-looking" nav item may be a Page, in which
  case it needs the per-post SEO metabox, not the term description.
- Snippet changes take **days to weeks** to surface in Google, and Google may still
  substitute its own text for a given query. Set that expectation with clients.

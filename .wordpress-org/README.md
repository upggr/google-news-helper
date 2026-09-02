# WordPress.org directory assets

These files are uploaded to the SVN `assets/` folder by `bin/svn-deploy.sh`.
They are **not** part of the plugin ZIP.

All required files are present. `icon.svg` and `banner.svg` are the sources —
edit those and re-run:

```bash
rsvg-convert -w 256 -h 256 icon.svg   -o icon-256x256.png
rsvg-convert -w 128 -h 128 icon.svg   -o icon-128x128.png
rsvg-convert -w 1544 -h 500 banner.svg -o banner-1544x500.png
rsvg-convert -w 772  -h 250 banner.svg -o banner-772x250.png
```

Screenshots were captured from a scratch WordPress install running the plugin,
not mocked up, so they must be retaken when the admin screens change.

Files:

| File | Size | Purpose |
|---|---|---|
| `icon-128x128.png` | 128×128 | Search results icon |
| `icon-256x256.png` | 256×256 | Retina icon |
| `banner-772x250.png` | 772×250 | Plugin page header |
| `banner-1544x500.png` | 1544×500 | Retina header |
| `screenshot-1.png` | any | Matches "1." under `== Screenshots ==` in readme.txt |
| `screenshot-2.png` | any | Matches "2." and so on |

Screenshot order must line up with the numbered list in `readme.txt`.

# Photography Portfolio

Static public portfolio plus an unprotected admin at `/admin`.

## Files

- `index.html` is the public site.
- `admin/index.html` is the admin UI.
- `admin/api.php` uploads originals, deletes files, and writes `photos/index.json`.
- `photos/index.json` is the hosted index file.
- Uploaded image URLs are stored as absolute `https://photography.pulkith.com/photos/...` URLs.

## Preview

Every page reads and writes the hosted content only:

- Index: `https://photography.pulkith.com/photos/index.json`
- API: `https://photography.pulkith.com/admin/api.php`
- Browser reads use `https://photography.pulkith.com/admin/api.php?action=list` so local previews are not blocked by static JSON CORS rules.

Use any static server to preview the same global content:

```sh
python3 -m http.server 8000
```

Then open:

- Public shell: `http://localhost:8000`
- Admin shell: `http://localhost:8000/admin`

## Deployment

Copy this folder to `photography.pulkith.com`. Make sure `photos/` is writable by PHP and keep `photos/index.json` in place. The upload API creates `photos/display/` and `photos/thumbs/` for optimized derivatives while preserving the original uploaded files. PHP GD is required for derivative generation; without it, the site falls back to original files.

The API also reads EXIF metadata when available. It uses `DateTimeOriginal` for the taken date, GPS EXIF for coordinates, and OpenStreetMap Nominatim reverse geocoding for city/region-style locations. PHP EXIF support is required for date/GPS extraction, and outbound HTTPS access is required for reverse geocoding.

The admin intentionally has no authentication because this was requested.

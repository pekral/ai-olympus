# Artwork sources

The PHP logo by Colin Viebrock comes from the [official PHP logo downloads](https://www.php.net/download-logos.php) and is licensed under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/). `brands/php.svg` preserves the downloaded artwork; the banner scales it without changing its colours or proportions.

The Laravel logo comes from the official [laravel/art repository](https://github.com/laravel/art/blob/master/laravel-logo.svg). `brands/laravel.svg` preserves the downloaded artwork and its original red colour. Laravel is a trademark of Laravel Holdings Inc. The logos identify the supported technologies and do not imply endorsement.

The banner composition in `social-preview.svg` and its rendered `social-preview.png` and `logo.png` variants is distributed under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/). This artwork licence does not change the package code's MIT licence.

## Rendering

The SVG contains embedded portrait and logo images and needs no network access to render. With [librsvg](https://gitlab.gnome.org/GNOME/librsvg) installed, run from the repository root:

```bash
rsvg-convert -w 1280 -h 640 assets/social-preview.svg -o assets/social-preview.png
rsvg-convert -w 960 -h 480 assets/social-preview.svg -o assets/logo.png
```

Update both digest values in `tests/Installer/SocialPreviewAssetTest.php` after rendering. Upload `social-preview.png` in GitHub **Settings → General → Social preview**; committing the file does not configure GitHub's repository setting.

The original 1254 × 1254 agent portraits remain in `agents/*.png`. The 160 × 160 JPEG files in `agents/thumbnails/` serve the 80-pixel README portraits and the smaller documentation portraits. To recreate a thumbnail on macOS:

```bash
sips -z 160 160 -s format jpeg -s formatOptions 80 assets/agents/athena.png --out assets/agents/thumbnails/athena.jpg
```

# CS Stale Cache Buster

A Joomla 5 system plugin that automatically appends file-modification timestamps to template CSS URLs, forcing browsers to fetch fresh copies when template stylesheets change.

## Description

When you edit your Joomla template's CSS files, browsers may continue serving the old cached version. CS Stale Cache Buster solves this by appending a file-modification timestamp to your template's CSS URLs. Unlike broader cache-busting approaches, this plugin **only targets your site template's CSS files** — it does not touch JavaScript, third-party extension assets, or anything else. This focused approach is safe for use with Cloudflare and other CDNs.

## Features

- Adds `?v=` timestamp parameter to template CSS URLs only
- Scans active site template's CSS directory (`/templates/{name}/css/` and `/media/templates/site/{name}/css/`)
- Template CSS scanner in admin to see all tracked files and their version hashes
- Prevent Admin Caching option — sends no-cache headers for CDN compatibility
- Debug mode to see which URLs were modified (HTML comment in page source)
- Full logging with log viewer, download, and clear functionality
- CDN safe — does not touch third-party extension or JavaScript assets
- 15 languages included

## Requirements

- Joomla 5.0 or higher
- PHP 8.1 or higher

## Installation

1. Download the latest release ZIP file
2. In Joomla admin, go to **System > Install > Extensions**
3. Upload and install the ZIP file
4. Go to **System > Plugins**
5. Search for "stale cache buster" and enable it
6. Click on the plugin to configure settings

## Configuration

### Basic Settings

- **Prevent Admin Caching**: Send no-cache headers on all administrator pages, preventing CDNs from caching the backend (default: No)
- **Debug Mode**: Adds an HTML comment before `</body>` listing every template CSS URL that was modified (default: No)
- **Scan Templates**: Button to scan installed templates and see their CSS files with modification dates and version hashes

### Logging Tab

- **Enable Logging**: Log every template CSS URL modification to a file for diagnostics (default: No)
- **Log Viewer**: Buttons to view, download, or clear the log

## How It Works

The plugin hooks into Joomla's `onAfterRender` event and scans the final HTML for `<link rel="stylesheet">` tags. It only modifies URLs that match CSS files found in the active site template's directories. For each matching URL, it appends the file's modification time as a hex-encoded version parameter (e.g., `template.css?v=65f3a1b2`). When you edit the CSS file, the timestamp changes, forcing browsers to fetch the updated version.

## Building from Source

Use 7-Zip to create the installation package:

```powershell
cd plg_system_stalecachebuster
& 'C:\Program Files\7-Zip\7z.exe' a -tzip '../plg_system_stalecachebuster_v2.0.0.zip' *
```

## License

This project is released under the GNU General Public License version 3 (GPLv3).

## Author

Cybersalt - [https://cybersalt.com](https://cybersalt.com)

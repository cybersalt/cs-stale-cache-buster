# CS Stale Cache Buster

A Joomla 5 system plugin that automatically appends file-modification timestamps to CSS and JavaScript URLs, solving stale browser cache issues.

## Description

Some Joomla extensions (like Template Creator CK, Maximenu CK, and others) output CSS and JavaScript files without version strings or cache-busting parameters. This means browsers may serve stale cached versions of these files even after updates. CS Stale Cache Buster fixes this by automatically appending a file-modification timestamp to local asset URLs in the rendered HTML.

## Features

- Adds `?v=` timestamp parameter to local CSS and JavaScript URLs
- Only targets local files (skips external URLs and data: URIs)
- Option to skip URLs that already have query string parameters
- Separately enable/disable for CSS and JavaScript
- Uses `onAfterRender` system event for guaranteed processing of final HTML output
- Zero configuration needed for most sites - just install and enable

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

- **Cache-bust CSS Files**: Add file-modification timestamps to CSS file URLs (default: Yes)
- **Cache-bust JavaScript Files**: Add file-modification timestamps to JavaScript file URLs (default: Yes)
- **Skip URLs with Existing Parameters**: Leave URLs that already have a query string unchanged (default: Yes)

## How It Works

The plugin hooks into Joomla's `onAfterRender` event and scans the final HTML output for `<link rel="stylesheet">` and `<script src="">` tags. For each local file URL found, it checks the file's modification time on disk and appends it as a hex-encoded version parameter (e.g., `style.css?v=65f3a1b2`). When the file changes, the timestamp changes, forcing browsers to fetch the updated version.

## Building from Source

Use 7-Zip to create the installation package (never use PowerShell's `Compress-Archive`):

```powershell
cd plg_system_stalecachebuster
& 'C:\Program Files\7-Zip\7z.exe' a -tzip '../plg_system_stalecachebuster_v1.0.0.zip' *
```

## License

GNU General Public License version 2 or later

## Author

Cybersalt - [https://cybersalt.com](https://cybersalt.com)

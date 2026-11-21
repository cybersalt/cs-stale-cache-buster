# Changelog

All notable changes to CS Stale Cache Buster will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### 🐛 Bug Fixes
- **Joomla 6 front end error**: The plugin no longer breaks the site on Joomla 6 when the Backward Compatibility plugin is switched off. It does not use any removed Joomla file classes any more (GitHub issue #2).
- **Language placeholders**: Texts such as the plugin name, description and install message are now loaded properly during an install or update, and the language files are also kept inside the plugin folder, so no raw language keys show up. The translations were refreshed to match the current settings (GitHub issue #1).
- **Windows servers**: Template CSS files are now found correctly on Windows hosts, so they get their version tag there too.
- **Update notices on Joomla 6**: The update feed now matches Joomla 5 and Joomla 6 sites (it only matched Joomla 5 before).

### 🚀 New Features
- **Log Viewer rebuilt**: It now opens inside the normal admin template and works in light and dark mode. It has clickable statistics cards, a time range, a CSS file and a search filter, paging, a plain-text dump, a CSV download, delete selected entries, and a Clear All button that asks for confirmation in a dialog instead of a browser pop-up.
- **Support tab**: Support email, support link and support name can be changed in the plugin settings. They are used in error messages and in the install card.
- **Install card**: After installing or updating, a card with next steps, a link to the plugin settings and the support details is shown.
- **Changelog button**: A `changelog.xml` file and a `changelogurl` in the manifest, so Joomla can show the change notes.

### 🌐 Multilingual
- **17 languages**: Added Norwegian Bokmal and Norwegian Nynorsk, and updated all other translations.

### 🔍 Security
- **Log tools need the right permissions**: Viewing the log needs permission to manage plugins. Clearing or deleting log entries also needs permission to edit plugins, a POST request and a valid security token.
- **Log file location**: The log is now kept in Joomla's log folder instead of a `logs` folder in the site root, under a protected file name (`stalecachebuster.log.php`) that the web server won't hand out. Logs from older versions are moved over and removed when you update.
- **What the log keeps**: Only the page path and query are logged (not the host name the visitor sent), long addresses are shortened, and the log viewer reads at most the newest 4 MB.
- **Quiet when the log can't be written**: If the log folder or file isn't writable, the page loads normally and nothing is logged, instead of showing PHP warnings.

### 🔧 Improvements
- **Settings page**: The Scan Templates and Clear Log buttons no longer use browser pop-ups, and file names from the scan are escaped.

## [2.0.1] - 2026-03-26

### 🐛 Bug Fixes
- **Custom form fields**: Fixed Scan Templates button and Log Viewer buttons not rendering. Uses `addfieldprefix` on fieldsets with short type names instead of full namespace paths.

## [2.0.0] - 2026-03-26

### 🛡️ Breaking Changes
- **Template CSS only**: Plugin now exclusively targets CSS files from the active site template. No longer modifies JavaScript or third-party extension CSS/JS URLs. This prevents conflicts with Cloudflare and other CDNs.
- **Removed settings**: Cache-bust CSS toggle, Cache-bust JS toggle, Skip Existing Parameters, and Exclude Paths have been removed (no longer needed with the focused approach).

### 🚀 New Features
- **Template CSS scanner**: Admin button scans installed templates and shows all their CSS files with modification dates and current version hashes.

### 🔧 Improvements
- **CDN safe**: Only touches template stylesheets, leaving all other assets untouched for Cloudflare/CDN compatibility.

## [1.3.0] - 2026-03-26

### 🌐 Multilingual
- **15 languages**: Added translations for Czech, Dutch, French, German, Greek, Italian, Japanese, Chinese Simplified, Polish, Portuguese (Brazil), Russian, Spanish, Swedish, and Turkish.

### 🔧 Improvements
- **Post-install link**: Now filters plugin list by name instead of attempting direct edit link (Joomla CSRF requirement).

## [1.2.0] - 2026-03-26

### 🚀 New Features
- **Exclude Paths**: Textarea to list path fragments that should be skipped (one per line). Useful for excluding specific extensions like JCE or MaxiMenu CK.
- **Prevent Admin Caching**: Toggle to send no-cache headers on all administrator pages, preventing CDNs like Cloudflare from caching the backend.
- **Debug Mode**: Adds an HTML comment before `</body>` listing every URL that was modified. View page source to see the log.
- **Logging with Viewer**: Full file-based logging of URL modifications with a dark-themed AJAX log viewer, download, and clear functionality.

### 🔧 Improvements
- **Same-domain URL handling**: Full URLs pointing to the same domain (e.g., `https://yoursite.com/media/...`) are now recognized as local and processed correctly.
- **HTML-only processing**: Plugin now skips non-HTML responses (JSON, feeds, etc.) to avoid interfering with API calls.

## [1.1.0] - 2026-03-25

### 🔧 Improvements
- **GPLv3 license**: Updated all files from GPLv2+ to GPLv3.
- **Plugin name**: Changed from "System - Jexter Stale Cache Buster" to "System - CS Stale Cache Buster".
- **Post-install link**: Filter search now uses friendly name instead of technical plugin name.

## [1.0.0] - 2026-03-20

### 🚀 New Features
- **Initial release**: Automatically appends file-modification timestamps to CSS and JavaScript URLs.
- **Cache-bust CSS Files**: Toggle to add timestamps to stylesheet URLs.
- **Cache-bust JavaScript Files**: Toggle to add timestamps to script URLs.
- **Skip Existing Parameters**: Option to leave URLs that already have query strings unchanged.
- **Local files only**: Skips external URLs and data: URIs.
- **Security**: Validates resolved file paths are within JPATH_ROOT.

# Changelog

All notable changes to CS Stale Cache Buster will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

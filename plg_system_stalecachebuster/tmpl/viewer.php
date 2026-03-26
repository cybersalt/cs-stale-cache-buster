<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Variables available:
 * @var string $token        CSRF token
 * @var string $ajaxUrl      Base AJAX URL
 * @var string $lang         Language tag
 * @var string $logFilePath  Full path to the log file
 */

// Handle clear action directly in the viewer
$clearResult = null;
$input = \Joomla\CMS\Factory::getApplication()->getInput();
if ($input->get('do_clear') === '1') {
    if (file_exists($logFilePath)) {
        $deleted = @unlink($logFilePath);
        if (!$deleted) {
            @file_put_contents($logFilePath, '');
        }
        $clearResult = ['success' => true];
    } else {
        $clearResult = ['success' => true, 'message' => 'No log file found'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CS Stale Cache Buster - Log Viewer</title>
    <style>
        :root {
            --bg: #1e1e2e;
            --surface: #2a2a3e;
            --border: #3a3a5e;
            --text: #cdd6f4;
            --text-dim: #888;
            --accent: #89b4fa;
            --green: #a6e3a1;
            --yellow: #f9e2af;
            --red: #f38ba8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        h1 { font-size: 1.4rem; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            padding: 8px 16px;
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            color: var(--text);
        }
        .btn:hover { border-color: var(--accent); }
        .btn-danger { border-color: var(--red); color: var(--red); }
        .btn-danger:hover { background: var(--red); color: var(--bg); }
        .stats-bar {
            display: flex;
            gap: 20px;
            background: var(--surface);
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat { display: flex; flex-direction: column; }
        .stat-label { font-size: 0.7rem; text-transform: uppercase; color: var(--text-dim); }
        .stat-value { font-size: 1.1rem; font-weight: bold; }
        .log-entry {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
        }
        .entry-header {
            padding: 12px 16px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .entry-header:hover { background: rgba(137, 180, 250, 0.05); }
        .entry-time { color: var(--text-dim); font-size: 0.85rem; white-space: nowrap; }
        .entry-page {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--accent);
            font-size: 0.85rem;
        }
        .entry-count {
            background: var(--accent);
            color: var(--bg);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: bold;
            white-space: nowrap;
        }
        .entry-details {
            display: none;
            padding: 0 16px 12px;
            border-top: 1px solid var(--border);
        }
        .entry-details.open { display: block; }
        .mod-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        .mod-table th {
            text-align: left;
            padding: 6px 8px;
            color: var(--text-dim);
            border-bottom: 1px solid var(--border);
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .mod-table td {
            padding: 6px 8px;
            border-bottom: 1px solid rgba(58, 58, 94, 0.5);
            word-break: break-all;
            font-family: monospace;
            font-size: 0.8rem;
        }
        .url-original { color: var(--text-dim); }
        .url-modified { color: var(--green); }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            align-items: center;
        }
        .pagination .info { color: var(--text-dim); font-size: 0.85rem; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-dim);
        }
        .clear-notice {
            background: var(--green);
            color: var(--bg);
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">

    <?php if ($clearResult && $clearResult['success']): ?>
    <div class="clear-notice">Log cleared successfully.</div>
    <?php endif; ?>

    <div class="header">
        <h1>CS Stale Cache Buster - Log Viewer</h1>
        <div class="actions">
            <button class="btn" onclick="loadLog()">Refresh</button>
            <a class="btn" href="<?php echo $ajaxUrl; ?>&action=download">Download</a>
            <button class="btn btn-danger" onclick="clearLog()">Clear Log</button>
        </div>
    </div>

    <div class="stats-bar">
        <div class="stat">
            <span class="stat-label">Entries</span>
            <span class="stat-value" id="stat-entries">-</span>
        </div>
        <div class="stat">
            <span class="stat-label">URLs Modified</span>
            <span class="stat-value" id="stat-urls">-</span>
        </div>
        <div class="stat">
            <span class="stat-label">Log Size</span>
            <span class="stat-value" id="stat-size">-</span>
        </div>
    </div>

    <div id="log-container">
        <div class="empty-state">Loading...</div>
    </div>

    <div class="pagination" id="pagination" style="display:none;">
        <button class="btn" id="btn-prev" onclick="prevPage()">Previous</button>
        <span class="info" id="page-info"></span>
        <button class="btn" id="btn-next" onclick="nextPage()">Next</button>
    </div>
</div>

<script>
const AJAX_URL = <?php echo json_encode($ajaxUrl); ?>;
const CLEAR_URL = AJAX_URL + '&action=clear';
let currentOffset = 0;
const PAGE_SIZE = 50;
let totalEntries = 0;

function loadLog() {
    fetch(AJAX_URL + '&action=view&lines=' + PAGE_SIZE + '&offset=' + currentOffset)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('log-container').innerHTML =
                    '<div class="empty-state">Error: ' + (data.error || 'Unknown') + '</div>';
                return;
            }

            totalEntries = data.total;
            document.getElementById('stat-entries').textContent = data.total;

            let totalUrls = 0;
            data.entries.forEach(e => { totalUrls += (e.modifications || []).length; });
            document.getElementById('stat-urls').textContent = totalUrls;

            if (data.entries.length === 0) {
                document.getElementById('log-container').innerHTML =
                    '<div class="empty-state">No log entries yet. Enable logging in plugin settings and visit some pages.</div>';
                document.getElementById('pagination').style.display = 'none';
                return;
            }

            let html = '';
            data.entries.forEach((entry, i) => {
                const mods = entry.modifications || [];
                const id = 'entry-' + currentOffset + '-' + i;
                html += '<div class="log-entry">';
                html += '<div class="entry-header" onclick="toggleEntry(\'' + id + '\')">';
                html += '<span class="entry-time">' + escHtml(entry.timestamp || '') + '</span>';
                html += '<span class="entry-page">' + escHtml(entry.page || '') + '</span>';
                html += '<span class="entry-count">' + mods.length + ' URL' + (mods.length !== 1 ? 's' : '') + '</span>';
                html += '</div>';
                html += '<div class="entry-details" id="' + id + '">';
                if (mods.length > 0) {
                    html += '<table class="mod-table"><thead><tr><th>Original</th><th>Modified</th></tr></thead><tbody>';
                    mods.forEach(m => {
                        html += '<tr>';
                        html += '<td class="url-original">' + escHtml(m.original || '') + '</td>';
                        html += '<td class="url-modified">' + escHtml(m.modified || '') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                }
                html += '</div></div>';
            });

            document.getElementById('log-container').innerHTML = html;

            // Pagination
            const pag = document.getElementById('pagination');
            if (data.total > PAGE_SIZE) {
                pag.style.display = 'flex';
                const start = currentOffset + 1;
                const end = Math.min(currentOffset + PAGE_SIZE, data.total);
                document.getElementById('page-info').textContent = start + '-' + end + ' of ' + data.total;
                document.getElementById('btn-prev').disabled = (currentOffset === 0);
                document.getElementById('btn-next').disabled = (end >= data.total);
            } else {
                pag.style.display = 'none';
            }
        })
        .catch(err => {
            document.getElementById('log-container').innerHTML =
                '<div class="empty-state">Failed to load log: ' + err.message + '</div>';
        });

    // Get file size
    fetch(AJAX_URL + '&action=view&lines=0&offset=0')
        .then(r => r.json())
        .then(data => {
            // Estimate size from total entries
            document.getElementById('stat-size').textContent = data.total > 0 ? '~' + Math.round(data.total * 0.3) + ' KB' : '0';
        })
        .catch(() => {});
}

function toggleEntry(id) {
    document.getElementById(id).classList.toggle('open');
}

function prevPage() {
    currentOffset = Math.max(0, currentOffset - PAGE_SIZE);
    loadLog();
}

function nextPage() {
    if (currentOffset + PAGE_SIZE < totalEntries) {
        currentOffset += PAGE_SIZE;
        loadLog();
    }
}

function clearLog() {
    if (!confirm('Clear all log entries?')) return;
    fetch(CLEAR_URL)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentOffset = 0;
                loadLog();
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
            }
        })
        .catch(err => alert('Failed: ' + err.message));
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Load on page open
loadLog();
</script>
</body>
</html>

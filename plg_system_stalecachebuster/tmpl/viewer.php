<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Log viewer, rendered inside the Atum administrator template (so it follows
 * Atum's light and dark colours). Everything is plain Bootstrap classes plus a
 * little scoped CSS built on Atum's own unprefixed variables.
 *
 * Variables set by StaleCacheBuster::renderViewer():
 *
 * @var   array     $filters   q, range, file, limit, start
 * @var   array     $rows      Entries on this page
 * @var   int       $total     Entries matching the filters
 * @var   int       $start     First row shown (0-based)
 * @var   array     $stats     all, 1h, 24h, 7d, urls, size
 * @var   string[]  $cssFiles  Distinct CSS files seen in the log
 * @var   \Closure  $link      Builds a viewer/dump/csv URL, keeping the filters
 * @var   bool      $logging   Whether logging is switched on
 * @var   string    $token     CSRF token name
 * @var   \Joomla\CMS\Document\HtmlDocument $document  The page document
 */

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Scheme + host of this site, for the page links (logged pages are path + query).
$siteHost = \Joomla\CMS\Uri\Uri::getInstance(\Joomla\CMS\Uri\Uri::root())->toString(['scheme', 'host', 'port']);

$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
};

$ranges = [
    ''    => 'PLG_SYSTEM_STALECACHEBUSTER_RANGE_ANY',
    '1h'  => 'PLG_SYSTEM_STALECACHEBUSTER_RANGE_1H',
    '24h' => 'PLG_SYSTEM_STALECACHEBUSTER_RANGE_24H',
    '7d'  => 'PLG_SYSTEM_STALECACHEBUSTER_RANGE_7D',
    '30d' => 'PLG_SYSTEM_STALECACHEBUSTER_RANGE_30D',
];

$cards = [
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_ENTRIES', 'value' => $stats['all'], 'url' => $link(['q' => '', 'range' => '', 'file' => '', 'start' => 0], 'viewer', false), 'class' => ''],
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_LAST_HOUR', 'value' => $stats['1h'], 'url' => $link(['q' => '', 'range' => '1h', 'file' => '', 'start' => 0], 'viewer', false), 'class' => 'text-success'],
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_LAST_24H', 'value' => $stats['24h'], 'url' => $link(['q' => '', 'range' => '24h', 'file' => '', 'start' => 0], 'viewer', false), 'class' => 'text-success'],
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_LAST_7D', 'value' => $stats['7d'], 'url' => $link(['q' => '', 'range' => '7d', 'file' => '', 'start' => 0], 'viewer', false), 'class' => 'text-success'],
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_URLS', 'value' => $stats['urls'], 'url' => $link(['q' => '', 'range' => '', 'file' => '', 'start' => 0], 'viewer', false), 'class' => ''],
    ['label' => 'PLG_SYSTEM_STALECACHEBUSTER_STAT_SIZE', 'value' => $formatSize($stats['size']), 'url' => $link([], 'download', false), 'class' => ''],
];

$filtered = $filters['q'] !== '' || $filters['range'] !== '' || $filters['file'] !== '';
$end      = min($start + $filters['limit'], $total);

$document->getWebAssetManager()->addInlineStyle(<<<'CSS'
.scb-viewer .scb-stat {
    display: block;
    height: 100%;
    color: inherit;
    text-decoration: none;
    border: 1px solid var(--border-color, rgba(127, 127, 127, 0.4));
    transition: transform .12s ease, box-shadow .12s ease;
}
.scb-viewer .scb-stat:hover,
.scb-viewer .scb-stat:focus {
    color: inherit;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
}
.scb-viewer .scb-stat-label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    opacity: .75;
}
.scb-viewer .scb-stat-value { font-size: 1.5rem; font-weight: 600; line-height: 1.2; }
.scb-viewer .scb-page { min-width: 0; flex: 1 1 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.scb-viewer pre.scb-pre {
    margin: .5rem 0 0;
    padding: .6rem .75rem;
    max-height: 320px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-all;
    color: inherit;
    border: 1px solid var(--border-color, rgba(127, 127, 127, 0.4));
    border-radius: .25rem;
    font-size: .8rem;
}
.scb-viewer .scb-arrow { opacity: .6; }
.scb-viewer .scb-new { color: var(--success, #198754); }
CSS);
?>
<div class="scb-viewer container-fluid py-3">

    <h1 class="h3 mb-3"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_TITLE'); ?></h1>

    <?php if (!$logging) : ?>
        <div class="alert alert-warning" role="alert"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_LOGGING_OFF'); ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <?php foreach ($cards as $card) : ?>
            <div class="col-6 col-md-4 col-xl-2">
                <a class="scb-stat card" href="<?php echo $e($card['url']); ?>">
                    <span class="card-body py-2 d-block">
                        <span class="scb-stat-label d-block"><?php echo Text::_($card['label']); ?></span>
                        <span class="scb-stat-value d-block <?php echo $card['class']; ?>"><?php echo $e($card['value']); ?></span>
                    </span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-primary" href="<?php echo $e($link()); ?>"><span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_REFRESH'); ?></a>
        <a class="btn btn-secondary" href="<?php echo $e($link([], 'dump')); ?>" target="_blank" rel="noopener"><span class="icon-file" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_DUMP'); ?></a>
        <a class="btn btn-secondary" href="<?php echo $e($link([], 'csv')); ?>"><span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_CSV'); ?></a>
        <a class="btn btn-secondary" href="<?php echo $e($link([], 'download', false)); ?>"><span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_DOWNLOAD_LOG'); ?></a>
        <button type="submit" class="btn btn-warning" id="scb-delete-btn" form="scb-delete-form" disabled><span class="icon-times" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_DELETE_SELECTED'); ?></button>
        <button type="button" class="btn btn-danger ms-auto" data-bs-toggle="modal" data-bs-target="#scb-clear-modal"><span class="icon-trash" aria-hidden="true"></span> <?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_CLEAR_ALL'); ?></button>
    </div>

    <form method="get" action="<?php echo $e(\Joomla\CMS\Uri\Uri::base() . 'index.php'); ?>" class="card card-body mb-3">
        <input type="hidden" name="option" value="com_ajax">
        <input type="hidden" name="plugin" value="stalecachebuster">
        <input type="hidden" name="group" value="system">
        <input type="hidden" name="format" value="html">
        <input type="hidden" name="tmpl" value="component">
        <input type="hidden" name="action" value="viewer">
        <input type="hidden" name="<?php echo $e($token); ?>" value="1">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-3">
                <label class="form-label" for="scb-q"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_SEARCH'); ?></label>
                <input type="search" class="form-control" id="scb-q" name="q" value="<?php echo $e($filters['q']); ?>" placeholder="<?php echo $e(Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_SEARCH_PLACEHOLDER')); ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="scb-range"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_RANGE'); ?></label>
                <select class="form-select" id="scb-range" name="range">
                    <?php foreach ($ranges as $value => $key) : ?>
                        <option value="<?php echo $e($value); ?>"<?php echo $filters['range'] === (string) $value ? ' selected' : ''; ?>><?php echo Text::_($key); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label" for="scb-file"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_FILE'); ?></label>
                <select class="form-select" id="scb-file" name="file">
                    <option value=""><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_FILE_ALL'); ?></option>
                    <?php foreach ($cssFiles as $cssFile) : ?>
                        <option value="<?php echo $e($cssFile); ?>"<?php echo $filters['file'] === $cssFile ? ' selected' : ''; ?>><?php echo $e($cssFile); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="scb-limit"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_LIMIT'); ?></label>
                <select class="form-select" id="scb-limit" name="limit">
                    <?php foreach ([10, 25, 50, 100, 200] as $size) : ?>
                        <option value="<?php echo $size; ?>"<?php echo $filters['limit'] === $size ? ' selected' : ''; ?>><?php echo $size; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_APPLY'); ?></button>
                <a class="btn btn-secondary flex-fill" href="<?php echo $e($link(['start' => 0], 'viewer', false)); ?>"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_FILTER_RESET'); ?></a>
            </div>
        </div>
    </form>

    <?php if (empty($rows)) : ?>
        <div class="alert alert-info" role="status">
            <?php echo Text::_($filtered ? 'PLG_SYSTEM_STALECACHEBUSTER_EMPTY_FILTERED' : 'PLG_SYSTEM_STALECACHEBUSTER_EMPTY_NONE'); ?>
        </div>
    <?php else : ?>
        <form method="post" id="scb-delete-form" action="<?php echo $e(\Joomla\CMS\Uri\Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=html&tmpl=component&action=delete'); ?>">
            <input type="hidden" name="q" value="<?php echo $e($filters['q']); ?>">
            <input type="hidden" name="range" value="<?php echo $e($filters['range']); ?>">
            <input type="hidden" name="file" value="<?php echo $e($filters['file']); ?>">
            <input type="hidden" name="limit" value="<?php echo (int) $filters['limit']; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="scb-select-all">
                <label class="form-check-label" for="scb-select-all"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_COL_SELECT'); ?></label>
            </div>

            <?php foreach ($rows as $entry) :
                $count = \count($entry['modifications']);
                $page  = (string) ($entry['page'] ?? '');
                ?>
                <div class="card mb-2 scb-entry">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <input class="form-check-input scb-select mt-0" type="checkbox" name="ids[]" value="<?php echo $e($entry['id']); ?>" aria-label="<?php echo $e(Text::_('PLG_SYSTEM_STALECACHEBUSTER_COL_SELECT')); ?>">
                        <span class="small text-nowrap"><?php echo $e($entry['timestamp'] ?? ''); ?></span>
                        <?php // Pages are logged as path + query; the link is built on this site's own address. Full URLs from older versions carry a visitor-supplied host, so they stay plain text. ?>
                        <?php if (str_starts_with($page, '/') && !str_starts_with($page, '//')) : ?>
                            <a class="scb-page" href="<?php echo $e($siteHost . $page); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo $e($page); ?>"><?php echo $e($page); ?></a>
                        <?php else : ?>
                            <span class="scb-page" title="<?php echo $e($page); ?>"><?php echo $e($page); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-success ms-auto"><?php echo $e(Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_ENTRY_URLS', $count)); ?></span>
                    </div>
                    <div class="card-body py-2">
                        <details>
                            <summary><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_ENTRY_SHOW_URLS'); ?></summary>
                            <pre class="scb-pre"><?php
                            foreach ($entry['modifications'] as $mod) {
                                echo $e(($mod['original'] ?? '')) . "\n" . '  <span class="scb-arrow">=&gt;</span> <span class="scb-new">' . $e(($mod['modified'] ?? '')) . "</span>\n";
                            }
                            ?></pre>
                        </details>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>

        <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="pagination">
            <span class="small"><?php echo $e(Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_PAGINATION_INFO', $start + 1, $end, $total)); ?></span>
            <span class="d-flex gap-2">
                <?php if ($start > 0) : ?>
                    <a class="btn btn-secondary" href="<?php echo $e($link(['start' => max(0, $start - $filters['limit'])])); ?>"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_PAGE_PREV'); ?></a>
                <?php endif; ?>
                <?php if ($end < $total) : ?>
                    <a class="btn btn-secondary" href="<?php echo $e($link(['start' => $start + $filters['limit']])); ?>"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_PAGE_NEXT'); ?></a>
                <?php endif; ?>
            </span>
        </nav>
    <?php endif; ?>

    <div class="modal fade" id="scb-clear-modal" tabindex="-1" aria-labelledby="scb-clear-title" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?php echo $e(\Joomla\CMS\Uri\Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=html&tmpl=component&action=clear'); ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="scb-clear-title"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM_TITLE'); ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $e(Text::_('PLG_SYSTEM_STALECACHEBUSTER_CANCEL')); ?>"></button>
                </div>
                <div class="modal-body p-3"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM'); ?></div>
                <div class="modal-footer">
                    <input type="hidden" name="viewer" value="1">
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_CANCEL'); ?></button>
                    <button type="submit" class="btn btn-danger" id="scb-clear-confirm"><?php echo Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM_YES'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$document->getWebAssetManager()->addInlineScript(<<<'JS'
// Atum's own script is switched off on component pages, so follow the browser's
// light/dark setting here when the template is set to "follow the operating system".
(function () {
    var root = document.documentElement;

    if (!root.hasAttribute('data-color-scheme-os') || !window.matchMedia) {
        return;
    }

    var query = window.matchMedia('(prefers-color-scheme: dark)');
    var apply = function () {
        if (query.matches) {
            root.setAttribute('data-color-scheme', 'dark');
            root.setAttribute('data-bs-theme', 'dark');
        } else {
            root.removeAttribute('data-color-scheme');
            root.removeAttribute('data-bs-theme');
        }
    };

    apply();

    if (query.addEventListener) {
        query.addEventListener('change', apply);
    }
})();

document.addEventListener('DOMContentLoaded', function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.scb-viewer .scb-select'));
    var all = document.getElementById('scb-select-all');
    var del = document.getElementById('scb-delete-btn');
    function sync() {
        var any = boxes.some(function (b) { return b.checked; });
        if (del) { del.disabled = !any; }
        if (all) { all.checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; }); }
    }
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            sync();
        });
    }
    sync();
});
JS);

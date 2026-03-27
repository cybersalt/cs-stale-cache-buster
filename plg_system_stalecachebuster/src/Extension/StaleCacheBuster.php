<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

namespace Cybersalt\Plugin\System\StaleCacheBuster\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class StaleCacheBuster extends CMSPlugin implements SubscriberInterface
{
    /**
     * Debug log of modifications made during this request.
     */
    private array $debugLog = [];

    /**
     * Cached list of template CSS paths to bust.
     */
    private ?array $templateCssPaths = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'        => 'onAfterInitialise',
            'onAfterRender'            => 'onAfterRender',
            'onAjaxStalecachebuster'   => 'onAjaxStalecachebuster',
        ];
    }

    /**
     * Send no-cache headers for the administrator backend.
     */
    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        if (!(int) $this->params->get('no_cache_admin', 0)) {
            return;
        }

        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT', true);
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $document = $app->getDocument();
        if ($document->getType() !== 'html') {
            return;
        }

        $body = $app->getBody();

        if (empty($body)) {
            return;
        }

        $debugMode = (int) $this->params->get('debug_mode', 0);
        $enableLog = (int) $this->params->get('enable_logging', 0);
        $pageUrl   = Uri::getInstance()->toString();

        // Only bust template CSS files
        $body = $this->bustTemplateCssUrls($body);

        // Debug comment
        if ($debugMode && !empty($this->debugLog)) {
            $comment = "\n<!-- CS Stale Cache Buster Debug Log\n";
            $comment .= "     Modified " . count($this->debugLog) . " template CSS URL(s):\n";
            foreach ($this->debugLog as $entry) {
                $comment .= "     " . $entry['original'] . "\n";
                $comment .= "       => " . $entry['modified'] . "\n";
            }
            $comment .= "-->\n";
            $body = str_replace('</body>', $comment . '</body>', $body);
        } elseif ($debugMode) {
            $body = str_replace('</body>', "\n<!-- CS Stale Cache Buster: No template CSS URLs modified -->\n</body>", $body);
        }

        if ($enableLog && !empty($this->debugLog)) {
            $this->writeLog($pageUrl, $this->debugLog);
        }

        $app->setBody($body);
    }

    // ---------------------------------------------------------------
    // Template CSS scanning and busting
    // ---------------------------------------------------------------

    /**
     * Get the list of CSS file paths from installed templates that should be busted.
     * Returns relative paths like: templates/cybersalt/css/template.css
     */
    private function getTemplateCssPaths(): array
    {
        if ($this->templateCssPaths !== null) {
            return $this->templateCssPaths;
        }

        $this->templateCssPaths = [];

        // Get the active site template(s)
        $app = $this->getApplication();
        $template = $app->getTemplate();

        // Scan the active template's CSS directory
        $templateCssDir = JPATH_ROOT . '/templates/' . $template . '/css';
        if (is_dir($templateCssDir)) {
            $files = Folder::files($templateCssDir, '\.css$', true, true);
            foreach ($files as $file) {
                $relativePath = str_replace(JPATH_ROOT . '/', '', str_replace('\\', '/', $file));
                $this->templateCssPaths[] = $relativePath;
            }
        }

        // Also check /media/templates/site/{template}/css (Joomla 5 media location)
        $mediaCssDir = JPATH_ROOT . '/media/templates/site/' . $template . '/css';
        if (is_dir($mediaCssDir)) {
            $files = Folder::files($mediaCssDir, '\.css$', true, true);
            foreach ($files as $file) {
                $relativePath = str_replace(JPATH_ROOT . '/', '', str_replace('\\', '/', $file));
                $this->templateCssPaths[] = $relativePath;
            }
        }

        return $this->templateCssPaths;
    }

    /**
     * Bust only template CSS URLs in the rendered HTML.
     */
    private function bustTemplateCssUrls(string $body): string
    {
        $cssPaths = $this->getTemplateCssPaths();

        if (empty($cssPaths)) {
            return $body;
        }

        $pattern = '/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($cssPaths) {
            $tag = $match[0];

            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return $tag;
            }

            $url = $hrefMatch[1];

            // Normalize to relative path for comparison
            $workingUrl = $this->normalizeSameDomainUrl($url);

            // Strip any existing query string for path comparison
            $cleanUrl = strtok($workingUrl, '?#');
            $cleanUrl = ltrim($cleanUrl, '/');

            // Check if this URL matches any template CSS file
            $isTemplateCss = false;
            foreach ($cssPaths as $cssPath) {
                if ($cleanUrl === $cssPath) {
                    $isTemplateCss = true;
                    break;
                }
            }

            if (!$isTemplateCss) {
                return $tag;
            }

            // Resolve file and get modification time
            $filePath = JPATH_ROOT . '/' . $cleanUrl;
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

            if (!is_file($filePath)) {
                return $tag;
            }

            $mtime = @filemtime($filePath);
            if ($mtime === false) {
                return $tag;
            }

            $version = dechex($mtime);

            // Replace or append version parameter
            if (str_contains($url, '?')) {
                $newUrl = $url . '&v=' . $version;
            } else {
                $newUrl = $url . '?v=' . $version;
            }

            $this->debugLog[] = [
                'original' => $url,
                'modified' => $newUrl,
            ];

            return str_replace($hrefMatch[1], $newUrl, $tag);
        }, $body) ?? $body;
    }

    /**
     * If a URL points to the same domain as this site, strip it down to a relative path.
     */
    private function normalizeSameDomainUrl(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $siteUrl  = Uri::root();
        $siteHost = Uri::getInstance($siteUrl)->getHost();
        $urlHost  = Uri::getInstance($url)->getHost();

        if (strtolower($siteHost) !== strtolower($urlHost)) {
            return $url;
        }

        $root = Uri::root(true);
        $path = Uri::getInstance($url)->getPath();

        if (!empty($root) && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }

        return ltrim($path, '/');
    }

    // ---------------------------------------------------------------
    // AJAX handler for template scan and log viewer
    // ---------------------------------------------------------------

    public function onAjaxStalecachebuster(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            $this->sendJsonResponse(['error' => 'Access denied']);
            return;
        }

        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise('core.manage', 'com_plugins')) {
            $this->sendJsonResponse(['error' => 'Insufficient permissions']);
            return;
        }

        if (!Session::checkToken('get') && !Session::checkToken('post')) {
            $this->sendJsonResponse(['error' => 'Invalid token']);
            return;
        }

        $action = $app->getInput()->get('action', 'view', 'cmd');

        switch ($action) {
            case 'scan':
                $result = $this->ajaxScanTemplates();
                break;

            case 'view':
                $result = $this->ajaxViewLog();
                break;

            case 'clear':
                $result = $this->ajaxClearLog();
                break;

            case 'download':
                $this->ajaxDownloadLog();
                return;

            case 'viewer':
                $this->loadLanguage();
                echo $this->ajaxRenderViewer();
                $app->close();
                return;

            default:
                $result = json_encode(['error' => 'Unknown action']);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $result;
        $app->close();
    }

    private function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        $this->getApplication()->close();
    }

    /**
     * Scan all installed site templates and return their CSS files.
     */
    private function ajaxScanTemplates(): string
    {
        $templates = [];

        // Scan /templates/ directory for site templates
        $templateDirs = Folder::folders(JPATH_ROOT . '/templates');

        foreach ($templateDirs as $tplName) {
            // Skip system template
            if ($tplName === 'system') {
                continue;
            }

            $cssFiles = [];

            // Check /templates/{name}/css/
            $cssDir = JPATH_ROOT . '/templates/' . $tplName . '/css';
            if (is_dir($cssDir)) {
                $files = Folder::files($cssDir, '\.css$', true, true);
                foreach ($files as $file) {
                    $relative = str_replace(JPATH_ROOT . '/', '', str_replace('\\', '/', $file));
                    $mtime = @filemtime($file);
                    $cssFiles[] = [
                        'path'     => $relative,
                        'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown',
                        'size'     => filesize($file),
                        'version'  => $mtime ? dechex($mtime) : '',
                    ];
                }
            }

            // Check /media/templates/site/{name}/css/
            $mediaCssDir = JPATH_ROOT . '/media/templates/site/' . $tplName . '/css';
            if (is_dir($mediaCssDir)) {
                $files = Folder::files($mediaCssDir, '\.css$', true, true);
                foreach ($files as $file) {
                    $relative = str_replace(JPATH_ROOT . '/', '', str_replace('\\', '/', $file));
                    $mtime = @filemtime($file);
                    $cssFiles[] = [
                        'path'     => $relative,
                        'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown',
                        'size'     => filesize($file),
                        'version'  => $mtime ? dechex($mtime) : '',
                    ];
                }
            }

            if (!empty($cssFiles)) {
                $templates[] = [
                    'name'  => $tplName,
                    'files' => $cssFiles,
                ];
            }
        }

        return json_encode([
            'success'   => true,
            'templates' => $templates,
        ]);
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    private function getLogFile(): string
    {
        return JPATH_ROOT . '/logs/stalecachebuster.log';
    }

    private function writeLog(string $pageUrl, array $modifications): void
    {
        $logFile = $this->getLogFile();
        $maxSize = 2 * 1024 * 1024;

        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            $backup = $logFile . '.old';
            @unlink($backup);
            @rename($logFile, $backup);
        }

        $entry = [
            'timestamp'     => date('Y-m-d H:i:s'),
            'page'          => $pageUrl,
            'modifications' => $modifications,
        ];

        $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function ajaxViewLog(): string
    {
        $logFile = $this->getLogFile();
        $input   = $this->getApplication()->getInput();
        $lines   = (int) $input->get('lines', 100, 'int');
        $offset  = (int) $input->get('offset', 0, 'int');

        if (!file_exists($logFile)) {
            return json_encode([
                'success' => true,
                'entries' => [],
                'total'   => 0,
                'message' => 'Log file does not exist yet.',
            ]);
        }

        $content  = file_get_contents($logFile);
        $allLines = array_filter(explode("\n", $content));
        $entries  = [];

        foreach ($allLines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $entries[] = $entry;
            }
        }

        $entries = array_reverse($entries);
        $total   = count($entries);

        if ($offset > 0 || $lines > 0) {
            $entries = array_slice($entries, $offset, $lines);
        }

        return json_encode([
            'success' => true,
            'entries' => $entries,
            'total'   => $total,
            'offset'  => $offset,
            'limit'   => $lines,
        ]);
    }

    private function ajaxClearLog(): string
    {
        $logFile = $this->getLogFile();

        if (file_exists($logFile)) {
            @unlink($logFile);
        }
        @unlink($logFile . '.old');

        return json_encode([
            'success' => true,
            'message' => 'Log cleared.',
        ]);
    }

    private function ajaxDownloadLog(): void
    {
        $logFile = $this->getLogFile();

        if (!file_exists($logFile)) {
            echo '';
            $this->getApplication()->close();
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="stalecachebuster_' . date('Y-m-d_His') . '.log"');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        $this->getApplication()->close();
    }

    private function ajaxRenderViewer(): string
    {
        $token       = Session::getFormToken();
        $ajaxUrl     = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&' . $token . '=1';
        $lang        = Factory::getLanguage()->getTag();
        $logFilePath = $this->getLogFile();

        ob_start();
        include __DIR__ . '/../../tmpl/viewer.php';
        return ob_get_clean();
    }
}

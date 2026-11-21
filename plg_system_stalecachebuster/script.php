<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

\defined('_JEXEC') or die;

use Cybersalt\Plugin\System\StaleCacheBuster\Helper\SupportHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    private const EXTENSION = 'plg_system_stalecachebuster';

    /** Plain defaults, used only if the SupportHelper class cannot be loaded at install time. */
    private const SUPPORT_URL = 'https://www.cybersalt.com/services/support-request-form';

    private const SUPPORT_LABEL = 'Cybersalt support';

    /**
     * English strings read straight from the package, used only when Joomla
     * did not manage to load the plugin's language file (so the card and the
     * installer never show raw language keys).
     */
    private array $fallbackStrings = [];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // No page to show a card on when installing from the command line.
        if (!Factory::getApplication()->isClient('administrator')) {
            return true;
        }

        try {
            $this->loadStrings($adapter);
            $this->renderInstallCard($type, $adapter);

            // Joomla shows the manifest description in its own box above the
            // card, and the card already leads with it.
            $adapter->getParent()->message = '';
        } catch (\Throwable $e) {
            // The card is a courtesy: it must never fail the install.
        }

        return true;
    }

    /**
     * Make sure the plugin's strings are loaded while the installer is still
     * running, from the package, from the administrator language folder and
     * from the plugin folder, and remember the English file as a last resort.
     */
    private function loadStrings(InstallerAdapter $adapter): void
    {
        $language = Factory::getApplication()->getLanguage();
        $source   = (string) $adapter->getParent()->getPath('source');
        $root     = (string) $adapter->getParent()->getPath('extension_root');
        $bases    = array_values(array_filter([$source, JPATH_ADMINISTRATOR, $root]));

        foreach ($bases as $base) {
            $language->load(self::EXTENSION . '.sys', $base);
        }

        foreach ($bases as $base) {
            $file = $base . '/language/en-GB/' . self::EXTENSION . '.sys.ini';

            if (is_file($file)) {
                $strings = @parse_ini_file($file, false, INI_SCANNER_NORMAL);

                if (\is_array($strings)) {
                    $this->fallbackStrings = $strings;
                    break;
                }
            }
        }
    }

    /**
     * Translated string; falls back to the English file from the package and
     * only then to the raw key.
     */
    private function t(string $key): string
    {
        $text = Text::_($key);

        if ($text === $key && isset($this->fallbackStrings[$key])) {
            return (string) $this->fallbackStrings[$key];
        }

        return $text;
    }

    private function e(string $key): string
    {
        return htmlspecialchars($this->t($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getSupportSentence(InstallerAdapter $adapter): array
    {
        $sentence = '';
        $url      = self::SUPPORT_URL;

        try {
            if (!class_exists(SupportHelper::class, false)) {
                $file = $adapter->getParent()->getPath('extension_root') . '/src/Helper/SupportHelper.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }

            if (class_exists(SupportHelper::class, false)) {
                $sentence = SupportHelper::getContactSentence();
                $url      = SupportHelper::getUrl();
            }
        } catch (\Throwable $e) {
            // Use the plain defaults below.
        }

        if ($sentence === '' || str_starts_with($sentence, 'PLG_SYSTEM_STALECACHEBUSTER_')) {
            $sentence = sprintf($this->t('PLG_SYSTEM_STALECACHEBUSTER_SUPPORT_CONTACT_WITH_DETAILS'), self::SUPPORT_LABEL, 'support@cybersalt.com / ' . self::SUPPORT_URL);
        }

        return [$sentence, $url];
    }

    private function getEditUrl(): string
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('stalecachebuster'));
            $id = (int) $db->setQuery($query)->loadResult();

            if ($id > 0) {
                return 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $id;
            }
        } catch (\Throwable $e) {
            // Fall through to the filtered list.
        }

        return 'index.php?option=com_plugins&view=plugins&filter[folder]=system&filter[search]=stalecachebuster';
    }

    private function renderInstallCard(string $type, InstallerAdapter $adapter): void
    {
        $isUpdate = $type === 'update';

        $title  = $this->e($isUpdate
            ? 'PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_TITLE_UPDATE'
            : 'PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_TITLE_INSTALL');
        $intro  = $this->e($isUpdate
            ? 'PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_INTRO_UPDATE'
            : 'PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_INTRO_INSTALL');
        $stepsH = $this->e('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_STEPS_HEADING');
        $linkP  = $this->e('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_LINK_PLUGIN');
        $vendor = $this->e('PLG_SYSTEM_STALECACHEBUSTER_VENDOR_NAME');
        $about  = $this->t('PLG_SYSTEM_STALECACHEBUSTER_DESCRIPTION');

        // Steps and the tip contain inline HTML (<strong>) that must survive
        // into the card, so they are not escaped. Translators keep the tags balanced.
        $steps = [];

        if (!$isUpdate) {
            $steps[] = $this->t('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_STEP_ENABLE');
        }

        $steps[] = $this->t('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_STEP_SCAN');
        $steps[] = $this->t('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_STEP_LOGGING');

        $stepsHtml = '';

        foreach ($steps as $step) {
            $stepsHtml .= '<li>' . $step . '</li>';
        }

        $note = $this->t('PLG_SYSTEM_STALECACHEBUSTER_INSTALL_CARD_NOTE_DEBUG');

        [$sentence, $supportUrl] = $this->getSupportSentence($adapter);
        $sentence = htmlspecialchars($sentence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $pluginUrl = htmlspecialchars($this->getEditUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vendorUrl = 'https://www.cybersalt.com';
        $logoUrl   = htmlspecialchars(Uri::root() . 'media/plg_system_stalecachebuster/logo.svg', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $supportLi = $supportUrl !== ''
            ? '&middot; <a href="' . htmlspecialchars($supportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($supportUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>'
            : '';

        echo <<<HTML
<style>
/* Light mode defaults (Joomla 5/6 default state). */
.cs-install-card {
    --cs-header-bg: #fff;
    --cs-header-title: #0102E1;
    --cs-header-border: rgba(0, 0, 0, 0.1);
}

/* Dark mode override. Joomla 5/6 uses both selectors depending on
 * version and template; matching both keeps this robust. */
html[data-bs-theme="dark"] .cs-install-card,
html[data-color-scheme="dark"] .cs-install-card {
    --cs-header-bg: #1f2937;
    --cs-header-title: #FE9904;
    --cs-header-border: rgba(255, 255, 255, 0.1);
}
@media (prefers-color-scheme: dark) {
    html[data-color-scheme-os] .cs-install-card {
        --cs-header-bg: #1f2937;
        --cs-header-title: #FE9904;
        --cs-header-border: rgba(255, 255, 255, 0.1);
    }
}

.cs-install-card .cs-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    background-color: var(--cs-header-bg);
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--cs-header-border);
}
.cs-install-card .cs-card-header img {
    height: 56px;
    width: auto;
    flex: 0 0 auto;
}
.cs-install-card .cs-card-header h3 {
    margin: 0;
    font-size: 1.4rem;
    color: var(--cs-header-title);
}

/* Action buttons stay orange in both modes: white text reads against the
 * orange in light or dark surroundings. The !important and the extra
 * specificity beat Joomla's admin link colour. */
.cs-install-card a.cs-cybersalt-btn,
.cs-install-card a.cs-cybersalt-btn:link,
.cs-install-card a.cs-cybersalt-btn:visited {
    background-color: #dc6b1a;
    border-color: #dc6b1a;
    color: #fff !important;
}
.cs-install-card a.cs-cybersalt-btn:hover,
.cs-install-card a.cs-cybersalt-btn:focus,
.cs-install-card a.cs-cybersalt-btn:active {
    background-color: #b85614;
    border-color: #b85614;
    color: #fff !important;
}
</style>
<div class="card my-3 cs-install-card">
    <div class="card-header cs-card-header">
        <img src="{$logoUrl}" alt="" />
        <h3>{$title}</h3>
    </div>
    <div class="card-body">
        <p class="mb-2">{$about}</p>
        <p class="lead">{$intro}</p>
        <h4>{$stepsH}</h4>
        <ol>{$stepsHtml}</ol>
        <p class="text-muted small mb-2">{$note}</p>
        <p class="text-muted small mb-2">{$sentence}</p>
        <hr>
        <p class="mb-0">
            <a class="btn btn-sm cs-cybersalt-btn" href="{$pluginUrl}">{$linkP}</a>
            &middot;
            <a href="{$vendorUrl}" target="_blank" rel="noopener noreferrer">{$vendor}</a>
            {$supportLi}
        </p>
    </div>
</div>
HTML;
    }
};

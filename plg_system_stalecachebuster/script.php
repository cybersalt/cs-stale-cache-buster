<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Language\Text;

class PlgSystemStalecachebusterInstallerScript
{
    public function postflight(string $type, InstallerAdapter $adapter): void
    {
        if ($type === 'install' || $type === 'update') {
            $this->showPostInstallMessage($type);
        }
    }

    protected function showPostInstallMessage(string $type): void
    {
        $messageKey = $type === 'update'
            ? 'PLG_SYSTEM_STALECACHEBUSTER_POSTINSTALL_UPDATED'
            : 'PLG_SYSTEM_STALECACHEBUSTER_POSTINSTALL_INSTALLED';

        $url = 'index.php?option=com_plugins&view=plugins&filter[search]=stale cache buster';

        echo '<div class="card mb-3" style="margin: 20px 0;">'
            . '<div class="card-body">'
            . '<h3 class="card-title">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER') . '</h3>'
            . '<p class="card-text">' . Text::_($messageKey) . '</p>'
            . '<a href="' . $url . '" class="btn btn-primary text-white">'
            . Text::_('PLG_SYSTEM_STALECACHEBUSTER_POSTINSTALL_OPEN')
            . '</a></div></div>';
    }
}

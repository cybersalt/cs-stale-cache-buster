<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Cybersalt\Plugin\System\StaleCacheBuster\Extension\StaleCacheBuster;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new StaleCacheBuster(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'stalecachebuster')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

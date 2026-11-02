<?php

/**
 * This file is part of Nolej Repository Object Plugin for ILIAS,
 * developed by OC Open Consulting to integrate ILIAS with Nolej
 * software by Neuronys.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2024 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/**
 * Checks for the H5P plugin, which this plugin depends on.
 *
 * This class must not use any H5P trait, interface or type in its
 * declaration: it is loaded on every request (also when the H5P plugin
 * is missing) to tell whether H5P is available, while
 * ilNolejH5PIntegrationGUI cannot even be declared without it.
 */
final class ilNolejH5PDependency
{
    /** @var string */
    private const H5P_PLUGIN_DIR = "./Customizing/global/plugins/Services/Repository/RepositoryObject/H5P";

    /** @var string */
    private const H5P_AUTOLOADER = self::H5P_PLUGIN_DIR . "/vendor/autoload.php";

    /**
     * Check if the H5P plugin is installed.
     * @return bool
     */
    public static function isInstalled(): bool
    {
        if (!file_exists(self::H5P_AUTOLOADER)) {
            return false;
        }

        require_once self::H5P_AUTOLOADER;

        try {
            $h5p_container = self::getPlugin()->getContainer();
            return $h5p_container->getRepositoryFactory()->general()->isMainPluginInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if the H5P plugin is active.
     * @return bool
     */
    public static function isActive(): bool
    {
        return self::getPlugin()->isActive();
    }

    /**
     * Get the H5P plugin object.
     * @return ilH5PPlugin
     */
    public static function getPlugin(): ilH5PPlugin
    {
        global $DIC;

        $component_factory = $DIC["component.factory"];
        return $component_factory->getPlugin(ilH5PPlugin::PLUGIN_ID);
    }
}

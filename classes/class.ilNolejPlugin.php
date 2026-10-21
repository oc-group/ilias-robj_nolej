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
 * Plugin main class
 */
class ilNolejPlugin extends ilRepositoryObjectPlugin
{
    /** @var string */
    public const PLUGIN_ID = "xnlj";

    /** @var string */
    public const PLUGIN_NAME = "Nolej";

    /** @var string */
    public const PLUGIN_DIR = "./Customizing/global/plugins/Services/Repository/RepositoryObject/Nolej";

    /** @var string */
    public const PERMALINK = "xnlj_modules";

    /** @var string */
    public const PREFIX = "rep_robj_xnlj";

    /** @var string */
    public const TABLE_CONFIG = "rep_robj_xnlj_config";

    /** @var string */
    public const TABLE_ACTIVITY = "rep_robj_xnlj_activity";

    /** @var string */
    public const TABLE_DOC = "rep_robj_xnlj_doc";

    /** @var string */
    public const TABLE_DATA = "rep_robj_xnlj_data";

    /** @var string */
    public const TABLE_H5P = "rep_robj_xnlj_hfp";

    /** @var string */
    public const TABLE_LP = "rep_robj_xnlj_lp";

    /** @var string id of the H5P repository object plugin */
    public const H5P_PLUGIN_ID = "xhfp";

    /** @var self|null */
    protected static ?self $instance = null;

    /** @var ilLogger */
    public ilLogger $logger;

    /** @var array<string, string> */
    protected static array $config = [];

    /**
     * Initialize plugin.
     * @return void
     */
    protected function init(): void
    {
        $this->logger = ilLoggerFactory::getLogger(self::PREFIX);

        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    /**
     * Register the GlobalScreen notification provider.
     *
     * The provider cannot be registered in init(): for repository object plugins init()
     * runs before the "global_screen" service exists. This hook runs once GlobalScreen is
     * available, and the provider collection is read lazily, so registering here is safe.
     * The renderer is returned unchanged.
     *
     * @param \ILIAS\DI\Container $dic
     * @return Closure
     */
    public function exchangeUIRendererAfterInitialization(\ILIAS\DI\Container $dic): Closure
    {
        if (
            isset($dic["global_screen"]) &&
            $this->provider_collection->getNotificationProvider() === null
        ) {
            $this->provider_collection->setNotificationProvider(
                new ilNolejNotificationProvider($dic, $this)
            );
        }

        return parent::exchangeUIRendererAfterInitialization($dic);
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        global $DIC;

        if (self::$instance === null) {
            /** @var ilComponentFactory $component_factory */
            $component_factory = $DIC["component.factory"];

            $plugin = $component_factory->getPlugin(self::PLUGIN_ID);
            if (!$plugin instanceof self) {
                throw new LogicException("Plugin " . self::PLUGIN_ID . " is not an " . self::class . ".");
            }
            self::$instance = $plugin;
        }

        return self::$instance;
    }

    /**
     * Must correspond to the plugin subdirectory
     * @return string
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Returns a list of all repository object types which can be a parent of this type.
     * @return string[]
     */
    public function getParentTypes(): array
    {
        return ["root", "cat", "crs", "grp", "fold", "lso", "prg"];
    }

    /**
     * Delete plugin tables.
     * @return void
     */
    protected function uninstallCustom(): void
    {
        $tables = [
            self::TABLE_CONFIG,
            self::TABLE_ACTIVITY,
            self::TABLE_DATA,
            self::TABLE_DOC,
            self::TABLE_H5P,
            self::TABLE_LP
        ];

        foreach ($tables as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->dropTable($table);
            }
        }
    }

    /**
     * Nolej objects cannot be copied.
     * @return bool
     */
    public function allowCopy(): bool
    {
        return false;
    }

    /**
     * Get the url to the plugin configuration GUI.
     * @return string
     */
    public function getConfigurationLink(): string
    {
        global $DIC;

        $ctrl = $DIC->ctrl();

        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "ref_id", "31");
        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "ctype", "Services");
        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "cname", "Repository");
        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "slot_id", "robj");
        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "plugin_id", self::PLUGIN_ID);
        $ctrl->setParameterByClass(ilNolejConfigGUI::class, "pname", self::PLUGIN_NAME);

        return $ctrl->getLinkTargetByClass(
            ["ilAdministrationGUI", "ilObjComponentSettingsGUI", ilNolejConfigGUI::class],
            ilNolejConfigGUI::CMD_CONFIGURE
        );
    }

    /**
     * @param string $keyword
     * @param string $defaultValue
     * @return string
     */
    public static function getConfig(string $keyword, string $defaultValue = ""): string
    {
        global $DIC;
        $db = $DIC->database();

        if (isset(self::$config[$keyword])) {
            return self::$config[$keyword];
        }

        $res = $db->queryF(
            "SELECT value FROM " . self::TABLE_CONFIG . " WHERE keyword = %s",
            ["text"],
            [$keyword]
        );

        $record = $db->fetchAssoc($res);
        if (!is_array($record)) {
            return $defaultValue;
        }

        self::$config[$keyword] = (string) $record["value"];
        return self::$config[$keyword];
    }

    /**
     * @param string $keyword
     * @param string $value
     * @return void
     */
    public static function saveConfig(string $keyword, string $value): void
    {
        global $DIC;
        $db = $DIC->database();

        self::$config[$keyword] = $value;

        $db->replace(
            self::TABLE_CONFIG,
            ["keyword" => ["text", $keyword]],
            ["value" => ["text", $value]]
        );
    }

    /**
     * Check if the H5P plugin is installed (it has an installed version).
     * Does not load the H5P plugin.
     * @return bool
     */
    public static function isH5PInstalled(): bool
    {
        global $DIC;
        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC["component.repository"];

        return $component_repository->hasPluginId(self::H5P_PLUGIN_ID)
            && $component_repository->getPluginById(self::H5P_PLUGIN_ID)->isInstalled();
    }

    /**
     * Check if the H5P plugin is installed and active.
     * Does not load the H5P plugin.
     * @return bool
     */
    public static function isH5PActive(): bool
    {
        global $DIC;
        /** @var ilComponentRepository $component_repository */
        $component_repository = $DIC["component.repository"];

        return $component_repository->hasPluginId(self::H5P_PLUGIN_ID)
            && $component_repository->getPluginById(self::H5P_PLUGIN_ID)->isActive();
    }

    /**
     * Get the H5P plugin object (an ilH5PPlugin).
     * Only call it if isH5PInstalled() is true.
     * @return ilPlugin
     */
    public static function getH5PPlugin(): ilPlugin
    {
        global $DIC;
        /** @var ilComponentFactory $component_factory */
        $component_factory = $DIC["component.factory"];

        return $component_factory->getPlugin(self::H5P_PLUGIN_ID);
    }

    /**
     * Get the HTML of an H5P activity.
     * @param int|string $contentId
     * @param string $type
     * @param bool $editable
     * @return string html
     */
    public static function renderH5P(int|string $contentId, string $type = "", bool $editable = false): string
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $db = $DIC->database();

        try {
            $result = $db->queryF(
                "SELECT id FROM " . self::TABLE_DATA
                    . " WHERE document_id = (SELECT document_id FROM " . self::TABLE_H5P . " WHERE content_id = %s)",
                ["integer"],
                [$contentId]
            );
            $row = $db->fetchAssoc($result);
            if (is_array($row)) {
                $objId = (int) $row["id"];
                $refIds = ilObject::_getAllReferences($objId);
                $refId = (int) reset($refIds);
                $obj_gui = new ilObjNolejGUI($refId);
                $h5p = new ilNolejH5PIntegrationGUI($obj_gui);
                return $h5p->getHTML((int) $contentId, $type, $editable);
            }

            throw new Exception("Activity not found.");
        } catch (Exception $e) {
            // Fallback.
            return $renderer->render($factory->messageBox()->failure($e->getMessage()));
        }
    }

    /**
     * Log a message.
     * @param string $a_message
     * @param int $a_level (info, warning, error, debug)
     * @return void
     */
    public function log(string $a_message, int $a_level = ilLogLevel::INFO): void
    {
        $this->logger->log($a_message, $a_level);
    }
}

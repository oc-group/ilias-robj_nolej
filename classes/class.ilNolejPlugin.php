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
    public const PERMALINK = "xnlj_modules";

    /** @var string */
    public const PREFIX = "rep_robj_xnlj";

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

    /** @var self|null */
    protected static ?self $instance = null;

    /** @var ilLogger */
    public ilLogger $logger;

    /**
     * Initialize plugin.
     * @return void
     */
    protected function init(): void
    {
        $this->logger = ilLoggerFactory::getLogger(self::PREFIX);

        if (self::$instance == null) {
            self::$instance = $this;
        }
    }

    /**
     * Exchange renderer after plugin initialization.
     *
     * Registers the GlobalScreen notification provider here, because for
     * repository object plugins init() runs before "global_screen" exists.
     *
     * If the H5P plugin is installed but not active, its renderer is used:
     * renderers are only exchanged by active plugins, and this plugin must
     * stay usable when H5P is inactive. If H5P is missing, or fails, the
     * default renderer is returned.
     * @see ilH5PPlugin::exchangeUIRendererAfterInitialization
     *
     * @param \ILIAS\DI\Container $dic
     * @return Closure
     */
    public function exchangeUIRendererAfterInitialization(\ILIAS\DI\Container $dic): Closure
    {
        if (
            $dic->offsetExists("global_screen") &&
            $this->provider_collection->getNotificationProvider() === null
        ) {
            $this->provider_collection->setNotificationProvider(
                new ilNolejNotificationProvider($dic, $this)
            );
        }

        // This runs on every request: a missing or broken H5P plugin must
        // never stop ILIAS, so fall back to the default renderer.
        try {
            if (ilNolejH5PDependency::isInstalled() && !ilNolejH5PDependency::isActive()) {
                $h5p = ilNolejH5PDependency::getPlugin();
                return $h5p->exchangeUIRendererAfterInitialization($dic);
            }
        } catch (Throwable $e) {
            $this->log(
                "H5P renderer exchange failed: " . get_class($e) . ": " . $e->getMessage(),
                ilLogLevel::ERROR
            );
        }

        // Returns the default callable without executing it.
        return $dic->raw("ui.renderer");
    }

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        global $DIC;

        if (self::$instance == null) {
            /** @var ilComponentFactory */
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
     * Returns a list of all repository object types which can be a parent of
     * this type.
     * @return string[]
     */
    public function getParentTypes(): array
    {
        $par_types = ["root", "cat", "crs", "grp", "fold", "lso", "prg"];
        return $par_types;
    }

    /**
     * Delete plugin tables.
     * @return void
     */
    protected function uninstallCustom(): void
    {
        global $DIC;

        $tables = [
            "rep_robj_xnlj_config",
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

        // Documents of every object.
        if (is_dir(ilObjNolej::dataDir())) {
            ilFileUtils::delDir(ilObjNolej::dataDir());
        }

        // Settings and encryption key.
        (new ilNolejSettings())->deleteAll();
        $storage = $DIC->filesystem()->storage();
        if ($storage->hasDir("xnlj")) {
            $storage->deleteDir("xnlj");
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
     * Check the CSRF token (rtoken) of the current request. Every command
     * that writes must call this before any effect.
     * @return bool
     */
    public static function hasValidRequestToken(): bool
    {
        global $DIC;

        try {
            $query = $DIC->http()->wrapper()->query();
            if (!$query->has(ilCtrlInterface::PARAM_CSRF_TOKEN)) {
                return false;
            }

            $sent = $query->retrieve(
                ilCtrlInterface::PARAM_CSRF_TOKEN,
                $DIC->refinery()->kindlyTo()->string()
            );
            return (new ilCtrlTokenRepository())->getToken()->verifyWith($sent);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the HTML of an H5P activity.
     * @param int $contentId
     * @param string $type
     * @param bool $editable
     * @return string html
     */
    public static function renderH5P($contentId, string $type = "", bool $editable = false): string
    {
        global $DIC;

        $factory = $DIC->ui()->factory();
        $renderer = $DIC->ui()->renderer();
        $db = $DIC->database();

        $plugin = self::getInstance();

        if (!ilNolejH5PDependency::isInstalled()) {
            return $renderer->render(
                $factory->messageBox()->failure($plugin->txt("err_h5p_not_installed"))
            );
        }

        try {
            $result = $db->queryF(
                "SELECT id FROM " . self::TABLE_DATA
                    . " WHERE document_id = (SELECT document_id FROM " . self::TABLE_H5P . " WHERE content_id = %s)",
                ["integer"],
                [$contentId]
            );
            $row = $db->fetchAssoc($result);
            if ($row) {
                $objId = $row["id"];
                $refIds = ilObject::_getAllReferences($objId);
                $refId = (int) reset($refIds);
                $obj_gui = new ilObjNolejGUI($refId);
                $h5p = new ilNolejH5PIntegrationGUI($obj_gui);
                return $h5p->getHTML((int) $contentId, $type, $editable);
            }

            return $renderer->render(
                $factory->messageBox()->failure($plugin->txt("err_activity_not_found"))
            );
        } catch (Throwable $e) {
            // Fallback: the detail goes to the log, not to the page.
            $plugin->log(
                "Cannot render H5P content {$contentId}: " . get_class($e) . ": " . $e->getMessage(),
                ilLogLevel::ERROR
            );
            return $renderer->render($factory->messageBox()->failure($plugin->txt("err_h5p_content")));
        }
    }

    /**
     * Log a message.
     * @param string $a_message
     * @param int $a_level (info, warning, error, debug)
     * @return void
     */
    public function log($a_message, int $a_level = ilLogLevel::INFO): void
    {
        $this->logger->log($a_message, $a_level);
    }
}

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

/**
 * Creation Form GUI class.
 *
 * @ilCtrl_Calls ilNolejCreationFormGUI: ilNolejTranscriptionFormGUI
 * @ilCtrl_isCalledBy ilNolejCreationFormGUI: ilObjPluginDispatchGUI, ilObjNolejGUI, ilNolejActivityManagementGUI
 */
class ilNolejCreationFormGUI extends ilNolejFormGUI
{
    /** @var string show mediapool media table */
    public const CMD_MEDIA_SELECTOR = "showMediaSelector";

    /** @var string select the media */
    public const CMD_MEDIA_SELECT = "selectMedia";

    /** @var string set mediapool filter */
    public const CMD_FILTER_SET = "mediaSelectorSetFilter";

    /** @var string reset mediapool filter */
    public const CMD_FILTER_RESET = "mediaSelectorResetFilter";

    public const PROP_TITLE = "title";
    public const PROP_MEDIA_SRC = "media_source";
    public const PROP_WEB = "web";
    public const PROP_WEB_SRC = "web_src";
    public const PROP_URL = "url";
    public const PROP_CONTENT = "content";
    public const PROP_AUDIO = "audio";
    public const PROP_VIDEO = "video";
    public const PROP_MOB = "mob";
    public const PROP_MOB_ID = "mob";
    public const PROP_FILE = "file";
    public const PROP_DOC = "document";
    public const PROP_TEXT = "freetext";
    public const PROP_TEXTAREA = "textarea";
    public const PROP_INPUT_FILE = "input_file";
    public const PROP_LANG = "language";

    /**
     * Handles incoming commmands.
     *
     * @throws ilException if command is not known
     * @return void
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd(self::CMD_SHOW);

        switch ($cmd) {
            case self::CMD_SHOW:
            case self::CMD_SAVE:
            case self::CMD_MEDIA_SELECTOR:
            case self::CMD_MEDIA_SELECT:
                $this->$cmd();
                break;

            case self::CMD_FILTER_SET:
            case self::CMD_FILTER_RESET:
                $this->showMediaSelector($cmd);
                $this->tpl->printToStdout();
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }
    }

    /**
     * Show creation form.
     * @return void
     */
    public function showForm(): void
    {
        $form = $this->form();
        $this->tpl->setContent($form->getHTML());

        $this->tpl->setRightContent(
            $this->renderer->render($this->manager->getWorkflow()->withActive(0))
        );
    }

    /**
     * Save creation form.
     * @return void
     */
    public function saveForm(): void
    {
        global $DIC;

        $form = $this->form();

        if (!$form->checkInput()) {
            // input not ok, then
            $form->setValuesByPost();
            $this->tpl->setContent($form->getHTML());
            return;
        }

        $apiTitle = $form->getInput(self::PROP_TITLE);
        $decrementedCredit = 1;

        /**
         * Set $apiUrl (signed)
         * Set $apiFormat
         * Set $decrementedCredit (all to 1)
         */
        $mediaSrc = $form->getInput(self::PROP_MEDIA_SRC);
        switch ($mediaSrc) {
            case self::PROP_WEB:
                /**
                 * No need to sign the url, just check the
                 * source type (content, or audio/video streaming)
                 */
                $apiUrl = $form->getInput(self::PROP_URL);
                $format = $form->getInput(self::PROP_WEB_SRC);
                switch ($format) {
                    case self::PROP_CONTENT:
                        $apiFormat = self::PROP_WEB;
                        break;

                    case self::PROP_AUDIO:
                    case self::PROP_VIDEO:
                        $apiFormat = $format;
                        break;
                }
                break;

            case self::PROP_MOB:
                // Use selected mob.
                $mobId = (int) $form->getInput(self::PROP_MOB_ID);
                if ($this->isValidMobId($mobId)) {
                    $apiFormat = $this->getMobFormat($mobId);
                    $apiUrl = $this->getSignedUrl($mobId, ilWACSignedPath::MAX_LIFETIME);
                }
                break;

            case self::PROP_FILE:
                // Save uploaded file as a media object.
                if (!isset($_FILES[self::PROP_INPUT_FILE], $_FILES[self::PROP_INPUT_FILE]["tmp_name"])) {
                    // File not set.
                    $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_file_upload"), true);
                    $form->setValuesByPost();
                    $this->tpl->setContent($form->getHTML());
                    return;
                }

                $mob = $this->saveMob();
                if ($mob == null) {
                    $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_file_upload"), true);
                    $form->setValuesByPost();
                    $this->tpl->setContent($form->getHTML());
                    return;
                }

                $apiFormat = $this->getMobFormat($mob->getId());
                $apiUrl = $this->getSignedUrl($mob->getId(), ilWACSignedPath::MAX_LIFETIME);
                break;

            case self::PROP_TEXT:
                // Save text as media object.
                $content = $form->getInput(self::PROP_TEXTAREA);
                $mob = $this->saveMob($content);
                $apiFormat = "freetext";
                $apiUrl = $this->getSignedUrl($mob->getId(), ilWACSignedPath::MAX_LIFETIME);
                break;
        }

        // Check url.
        if (!$apiUrl || $apiUrl == "") {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_media_url_empty"), true);
            $form->setValuesByPost();
            $this->tpl->setContent($form->getHTML());
            return;
        }

        // Check format.
        if (!$apiFormat || $apiFormat == "") {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_media_format_unknown"), true);
            $form->setValuesByPost();
            $this->tpl->setContent($form->getHTML());
            return;
        }

        $apiLanguage = $form->getInput(self::PROP_LANG);
        $apiAutomaticMode = false;

        // Update object title if it differs from the current one.
        if ($apiTitle != "" && $apiTitle != $this->obj_gui->getObject()->getTitle()) {
            $this->obj_gui->getObject()->setTitle($apiTitle);
            $this->obj_gui->getObject()->update();
        }

        $api = new ilNolejAPI();
        $webhookUrl = ILIAS_HTTP_PATH . "/goto.php?target=xnlj_webhook";

        $result = $api->post(
            "/documents",
            [
                "userID" => $DIC->user()->getId(),
                "organisationID" => ($DIC->settings()->get("short_inst_name") ?? "ILIAS") . " [ILIAS Plugin]",
                "title" => $apiTitle,
                "decrementedCredit" => $decrementedCredit,
                "docURL" => $apiUrl,
                "webhookURL" => $webhookUrl,
                "mediaType" => $apiFormat,
                "automaticMode" => $apiAutomaticMode,
                "language" => $apiLanguage
            ],
            true
        );

        if (!is_object($result) || !property_exists($result, "id") || !is_string($result->id)) {
            $message = print_r($result, true);
            if (property_exists($result, "errorMessage")) {
                $message = sprintf(
                    "<details><summary style='display: list-item;'>%s</summary><br><pre>%s</pre></details>",
                    $result->errorMessage,
                    print_r($result, true)
                );
            }
            $this->tpl->setOnScreenMessage(
                "failure",
                sprintf(
                    $this->plugin->txt("err_doc_response"),
                    $message
                )
            );
            $form->setValuesByPost();
            $this->tpl->setContent($form->getHTML());
            return;
        }

        $this->db->manipulateF(
            "UPDATE " . ilNolejPlugin::TABLE_DATA . " SET"
                . " document_id = %s WHERE id = %s;",
            ["text", "integer"],
            [$result->id, $this->obj_gui->getObject()->getId()]
        );

        $this->db->manipulateF(
            "INSERT INTO " . ilNolejPlugin::TABLE_DOC
                . " (title, status, consumed_credit, doc_url, media_type, automatic_mode, language, document_id)"
                . "VALUES (%s, %s, %s, %s, %s, %s, %s, %s);",
            ["text", "integer", "integer", "text", "text", "text", "text", "text"],
            [
                $this->obj_gui->getObject()->getTitle(),
                ilNolejActivityManagementGUI::STATUS_CREATION_PENDING,
                $decrementedCredit,
                $apiUrl,
                $apiFormat,
                ilUtil::tf2yn($apiAutomaticMode),
                $apiLanguage,
                $result->id
            ]
        );

        $ass = new ilNolejActivity($result->id, $DIC->user()->getId(), "transcription");
        $ass->withStatus("ok")
            ->withCode(0)
            ->withErrorMessage("")
            ->withConsumedCredit($decrementedCredit)
            ->store();

        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("action_transcription"), true);
        $this->ctrl->redirectByClass(ilNolejTranscriptionFormGUI::class, ilNolejTranscriptionFormGUI::CMD_SHOW);
    }

    /**
     * Init creation form.
     * @return ilPropertyFormGUI
     */
    protected function form(): ilPropertyFormGUI
    {
        $this->lng->loadLanguageModule("meta");
        $this->lng->loadLanguageModule("content");

        $form = new ilPropertyFormGUI();
        $form->setTitle($this->plugin->txt("tab_creation"));

        if ($this->status != ilNolejActivityManagementGUI::STATUS_CREATION) {
            // Show module information.

            $form->setOpenTag(false);
            $form->setCloseTag(false);

            $title = new ilNonEditableValueGUI($this->plugin->txt("prop_" . self::PROP_TITLE), self::PROP_TITLE);
            $title->setValue($this->obj_gui->getObject()->getTitle());
            $form->addItem($title);

            $mediaSource = new ilNonEditableValueGUI($this->plugin->txt("prop_" . self::PROP_MEDIA_SRC), self::PROP_MEDIA_SRC);
            $mediaSource->setValue($this->obj_gui->getObject()->getDocumentSource());
            $mediaSource->setInfo($this->plugin->txt("prop_" . $this->obj_gui->getObject()->getDocumentMediaType()));
            $form->addItem($mediaSource);

            $language = new ilNonEditableValueGUI($this->plugin->txt("prop_" . self::PROP_LANG), self::PROP_LANG);
            $language->setValue($this->lng->txt("meta_l_" . $this->obj_gui->getObject()->getDocumentLang()));
            $form->addItem($language);

            return $form;
        }

        /**
         * Module title
         * By default is the Object title, it can be changed here.
         */
        $title = new ilTextInputGUI($this->plugin->txt("prop_" . self::PROP_TITLE), self::PROP_TITLE);
        $title->setInfo($this->plugin->txt("prop_" . self::PROP_TITLE . "_info"));
        $title->setValue($this->obj_gui->getObject()->getTitle());
        $title->setMaxLength(250);
        $form->addItem($title);

        // Content limits.
        $limits = new ilNonEditableValueGUI();
        $limits->setInfo($this->contentLimitsInfo());
        $form->addItem($limits);

        /**
         * Choose a source to analyze.
         * - Web (url):
         *   - Web page content;
         *   - Audio streaming;
         *   - Video streaming.
         * - MediaPool (mob_id)
         * - Document (file upload)
         * - Text (textarea)
         */
        $mediaSource = new ilRadioGroupInputGUI($this->plugin->txt("prop_" . self::PROP_MEDIA_SRC), self::PROP_MEDIA_SRC);
        $mediaSource->setRequired(true);
        $form->addItem($mediaSource);

        /* Source: WEB or Streaming Audio/Video */
        $mediaWeb = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_WEB), self::PROP_WEB);
        $mediaWeb->setInfo($this->plugin->txt("prop_" . self::PROP_WEB . "_info"));
        $mediaSource->addOption($mediaWeb);

        /* Source URL */
        $url = new ilUriInputGUI($this->plugin->txt("prop_" . self::PROP_URL), self::PROP_URL);
        $url->setRequired(true);
        $mediaWeb->addSubItem($url);

        /* Web Source Type */
        $mediaSourceType = new ilRadioGroupInputGUI($this->plugin->txt("prop_" . self::PROP_WEB_SRC), self::PROP_WEB_SRC);
        $mediaSourceType->setRequired(true);
        $mediaWeb->addSubItem($mediaSourceType);

        /* Source Web page content */
        $srcContent = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_CONTENT), self::PROP_CONTENT);
        $srcContent->setInfo($this->plugin->txt("prop_" . self::PROP_CONTENT . "_info"));
        $mediaSourceType->addOption($srcContent);

        /* Source Audio */
        $srcAudio = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_AUDIO), self::PROP_AUDIO);
        $srcAudio->setInfo($this->plugin->txt("prop_" . self::PROP_AUDIO . "_info"));
        $mediaSourceType->addOption($srcAudio);

        /* Source Video: YouTube, Vimeo, Wistia */
        $srcVideo = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_VIDEO), self::PROP_VIDEO);
        $srcVideo->setInfo($this->plugin->txt("prop_" . self::PROP_VIDEO . "_info"));
        $mediaSourceType->addOption($srcVideo);

        /* Source: Media from MediaPool */
        $mediaMob = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_MOB), self::PROP_MOB);
        $mediaMob->setInfo($this->plugin->txt("prop_" . self::PROP_MOB . "_info"));
        $mediaSource->addOption($mediaMob);

        /* Mob ID */
        $mob = new ilFormSectionHeaderGUI();
        $mobIdInput = new ilHiddenInputGUI(self::PROP_MOB_ID);
        $tree = $this->getPoolSelectorGUI();
        $tree->handleCommand();

        $selectorModal = $this->factory->modal()->roundtrip(
            $this->lng->txt("cont_select_media_pool"),
            [$this->factory->legacy($tree->getHTML())]
        );

        $selectorButton = $this->factory->button()->shy(
            $this->lng->txt("cont_mob_from_media_pool"),
            "#"
        )->withOnClick($selectorModal->getShowSignal());

        $mobSelector = $this->factory->item()->standard($selectorButton);

        if (isset($_GET["mob_id"]) && $this->isValidMobId((int) $_GET["mob_id"])) {
            $mobId = (int) $_GET["mob_id"];
            $path = ilObjMediaObject::_lookupItemPath($mobId);
            $title = ilObject::_lookupTitle($mobId);

            $mobSelector = $mobSelector->withDescription($title)
                ->withProperties([
                    $this->lng->txt("id") => $mobId,
                    $this->lng->txt("mob") => $title,
                ]);

            // Add media preview.
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ilNolejAPI::TYPE_AUDIO)) {
                $audio = $this->factory->player()->audio($path);
                $mobSelector = $mobSelector->withLeadText($this->renderer->render($audio));
            } elseif (in_array($extension, ilNolejAPI::TYPE_VIDEO)) {
                $video = $this->factory->player()->video($path);
                $mobSelector = $mobSelector->withLeadText($this->renderer->render($video));
            }

            $mediaSource->setValue(self::PROP_MOB);
            $mobIdInput->setValue($mobId);
        }
        $mob->setInfo($this->renderer->render([$mobSelector, $selectorModal]));
        $mediaMob->addSubItem($mob);
        $mediaMob->addSubItem($mobIdInput);

        /**
         * Source: File upload
         * Upload audio/video/documents/text files in the plugin data directory.
         * The media type is taken from the file extension.
         */
        $mediaFile = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_FILE), self::PROP_FILE);
        $mediaFile->setInfo($this->plugin->txt("prop_" . self::PROP_FILE . "_info"));
        $mediaSource->addOption($mediaFile);

        /* File upload */
        $file = new ilFileInputGUI("", self::PROP_INPUT_FILE);
        $file->setRequired(true);
        $file->setSuffixes(ilNolejAPI::TYPE_SUPPORTED);
        $mediaFile->addSubItem($file);

        /**
         * Source: Text
         * Write an html text that need to be saved just like uploaded files
         * (with .html extension).
         */
        $mediaText = new ilRadioOption($this->plugin->txt("prop_" . self::PROP_TEXT), self::PROP_TEXT);
        $mediaText->setInfo($this->plugin->txt("prop_" . self::PROP_TEXT . "_info"));
        $mediaSource->addOption($mediaText);

        /* Text area */
        $txt = new ilTextAreaInputGUI("", self::PROP_TEXTAREA);
        $txt->setRows(50);
        $txt->setMinNumOfChars(500);
        $txt->setMaxNumOfChars(50000);
        $txt->usePurifier(false);
        if (ilObjAdvancedEditing::_getRichTextEditor() === "tinymce") {
            $txt->setUseRte(true);
            $txt->setRteTags([
                "h1",
                "h2",
                "h3",
                "p",
                "ul",
                "ol",
                "li",
                "br",
                "strong",
                "u",
                "i",
            ]);
            $txt->setRTERootBlockElement("");
            $txt->disableButtons([
                "charmap",
                "justifyright",
                "justifyleft",
                "justifycenter",
                "justifyfull",
                "alignleft",
                "aligncenter",
                "alignright",
                "alignjustify",
                "anchor",
                "pasteword"
            ]);
            // $txt->setPurifier(\ilHtmlPurifierFactory::_getInstanceByType('frm_post'));
        }
        $txt->setRequired(true);
        $mediaText->addSubItem($txt);

        /**
         * Source language
         */
        $language = new ilSelectInputGUI($this->plugin->txt("prop_" . self::PROP_LANG), self::PROP_LANG);
        $language->setInfo($this->plugin->txt("prop_" . self::PROP_LANG . "_info"));
        $language->setOptions([
            "en" => $this->lng->txt("meta_l_en"),
            "fr" => $this->lng->txt("meta_l_fr"),
            "it" => $this->lng->txt("meta_l_it"),
            "de" => $this->lng->txt("meta_l_de"),
            "pt" => $this->lng->txt("meta_l_pt"),
            "es" => $this->lng->txt("meta_l_es"),
            "nl" => $this->lng->txt("meta_l_nl")
        ]);
        $language->setRequired(true);
        $form->addItem($language);

        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->addCommandButton(self::CMD_SAVE, $this->plugin->txt("cmd_create"));

        return $form;
    }

    /**
     * Get the content limits popover.
     * @return string
     */
    protected function contentLimitsInfo(): string
    {
        $popover = $this->factory->popover()->listing([
            $this->factory->item()->group(
                "",
                [
                    $this->factory->item()->standard($this->plugin->txt("limit_audio"))
                        ->withProperties([
                            $this->plugin->txt("limit_max_duration") => sprintf($this->plugin->txt("limit_minutes"), 50),
                            $this->plugin->txt("limit_min_characters") => "500",
                            $this->plugin->txt("limit_max_size") => "500 MB",
                            $this->plugin->txt("limit_type") => implode(", ", ilNolejAPI::TYPE_AUDIO),
                        ]),
                    $this->factory->item()->standard($this->plugin->txt("limit_video"))
                        ->withProperties([
                            $this->plugin->txt("limit_max_duration") => sprintf($this->plugin->txt("limit_minutes"), 50),
                            $this->plugin->txt("limit_min_characters") => "500",
                            $this->plugin->txt("limit_max_size") => "500 MB",
                            $this->plugin->txt("limit_type") => implode(", ", ilNolejAPI::TYPE_VIDEO),
                        ]),
                    $this->factory->item()->standard($this->plugin->txt("limit_doc"))
                        ->withProperties([
                            $this->plugin->txt("limit_max_pages") => 50,
                            $this->plugin->txt("limit_min_characters") => "500",
                            $this->plugin->txt("limit_max_size") => "500 MB",
                            $this->plugin->txt("limit_type") => implode(", ", ilNolejAPI::TYPE_DOC),
                        ]),
                ]
            ),
        ])->withTitle($this->plugin->txt("limit_content"));

        $button = $this->factory->button()->standard($this->plugin->txt("limit_content"), "#")
            ->withOnClick($popover->getShowSignal());

        return $this->renderer->render([$popover, $button]);
    }

    /**
     * Get the MediaPool selector tree GUI.
     * @return ilPoolSelectorGUI
     */
    protected function getPoolSelectorGUI()
    {
        $tree = new ilPoolSelectorGUI($this, self::CMD_SHOW, $this, self::CMD_MEDIA_SELECTOR);
        $tree->setTypeWhiteList(["root", "cat", "grp", "fold", "crs", "mep"]);
        $tree->setClickableTypes(["mep"]);
        return $tree;
    }

    /**
     * Check that the gived mob id is a valid video.
     * @param int $mobId
     * @return bool
     */
    protected function isValidMobId($mobId)
    {
        if ($mobId <= 0) {
            return false;
        }

        $path = ilObjMediaObject::_lookupItemPath($mobId, false, false);
        if (!file_exists($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ilNolejAPI::TYPE_SUPPORTED);
    }

    /**
     * Show video selector modal.
     * @param string $filter
     */
    public function showMediaSelector($filter = "")
    {
        global $DIC;

        // Check SESSION data and user permissions.
        if (
            empty($_GET["pool_ref_id"]) ||
            !$DIC->access()->checkAccess("read", "", $_GET["pool_ref_id"]) ||
            ilObject::_lookupType(ilObject::_lookupObjId($_GET["pool_ref_id"])) != "mep"
        ) {
            // Cannot access this mediapool. Return to configuration.
            $this->ctrl->redirect($this, self::CMD_SHOW);
            return;
        }

        $this->ctrl->saveParameter($this, ["pool_ref_id"]);

        $toolbar = new ilToolbarGUI();

        $this->lng->loadLanguageModule("mep");
        $this->lng->loadLanguageModule("content");

        $pool_view = "folder";
        if (in_array($_GET["pool_view"], ["folder", "all"])) {
            $pool_view = $_GET["pool_view"];
        }

        // Override back target.
        $DIC->tabs()->clearTargets();
        $DIC->tabs()->setBackTarget($this->lng->txt("back"), $this->ctrl->getLinkTarget($this, self::CMD_SHOW));

        // View mode: pool view (folders/all media objects).
        $actions = [];

        $this->ctrl->setParameter($this, "pool_view", "folder");
        $actions[$this->lng->txt("folders")] = $this->ctrl->getLinkTarget($this, self::CMD_MEDIA_SELECTOR);

        $this->ctrl->setParameter($this, "pool_view", "all");
        $actions[$this->lng->txt("mep_all_mobs")] = $this->ctrl->getLinkTarget($this, self::CMD_MEDIA_SELECTOR);

        $this->ctrl->setParameter($this, "pool_view", $pool_view);
        $aria_label = $this->lng->txt("cont_change_pool_view");
        $view_control = $this->factory->viewControl()
            ->mode($actions, $aria_label)
            ->withActive($this->lng->txt($pool_view == "folder" ? "folders" : "mep_all_mobs"));

        $toolbar->addComponent($view_control);

        $pool = new ilObjMediaPool($_GET["pool_ref_id"]);
        $tmode = ilMediaPoolTableGUI::IL_MEP_SELECT;

        // Handle table sub commands and get the table.

        if ($filter == self::CMD_FILTER_SET) {
            // Apply filter.
            $mpool_table = new ilMediaPoolTableGUI(
                $this,
                self::CMD_MEDIA_SELECTOR,
                $pool,
                "mep_folder",
                $tmode,
                $pool_view == "all"
            );
            $mpool_table->resetOffset();
            $mpool_table->writeFilterToSession();
        }

        if ($filter == self::CMD_FILTER_RESET) {
            // Reset filter.
            $mpool_table = new ilMediaPoolTableGUI(
                $this,
                self::CMD_MEDIA_SELECTOR,
                $pool,
                "mep_folder",
                $tmode,
                $pool_view == "all"
            );
            $mpool_table->resetOffset();
            $mpool_table->resetFilter();
        }

        // Show table.
        $mpool_table = new ilMediaPoolTableGUI(
            $this,
            self::CMD_MEDIA_SELECTOR,
            $pool,
            "mep_folder",
            $tmode,
            $pool_view == "all"
        );

        $this->filterValidMediaObjects($mpool_table);

        $mpool_table->setInsertCommand(self::CMD_MEDIA_SELECT);
        $mpool_table->setFilterCommand(self::CMD_FILTER_SET);
        $mpool_table->setResetCommand(self::CMD_FILTER_RESET);

        $this->tpl->setContent($toolbar->getHTML() . $mpool_table->getHTML());
    }

    /**
     * Display videos only.
     * @param ilMediaPoolTableGUI $mpool_table
     * @return void
     */
    protected function filterValidMediaObjects($mpool_table)
    {
        $mediaObjects = $mpool_table->getData();
        $validMediaObjects = [];

        foreach ($mediaObjects as $mediaObject) {
            // Allow folders.
            if ($mediaObject["type"] == "fold") {
                $validMediaObjects[] = $mediaObject;
                continue;
            }

            // Skip non-media objects
            if ($mediaObject["type"] != "mob") {
                continue;
            }

            // Media item path.
            $mediaItemId = $mediaObject["foreign_id"];
            if ($this->isValidMobId($mediaItemId)) {
                $validMediaObjects[] = $mediaObject;
            }
        }

        // Replace table data.
        $mpool_table->setData($validMediaObjects);
    }

    /**
     * Confirm the selected source.
     * @return void
     */
    public function selectMedia()
    {
        // Check POST data.
        if (!isset($_POST["id"]) || !is_array($_POST["id"]) || empty($_POST["id"])) {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("file_not_valid"), true);
            $this->ctrl->redirect($this, self::CMD_SHOW);
            return;
        }

        // Item id relative to the mediapool. The table allows multiple media to be selected,
        // but only one can be set as the tile image.
        $mediapoolItemId = $_POST["id"][0];

        // Media item id.
        $mobId = ilMediaPoolItem::lookupForeignId($mediapoolItemId);

        // Check mob id validity.
        if ($this->isValidMobId($mobId)) {
            $this->ctrl->setParameter($this, "mob_id", $mobId);
        } else {
            $this->tpl->setOnScreenMessage("failure", $this->lng->txt("file_not_valid"), true);
        }

        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * Get the signed URL for Nolej to see the media object via webhook.
     * @param int $mobId
     * @param int $ttl in seconds
     * @return string url
     */
    protected function getSignedUrl(int $mobId, int $ttl = 10): string
    {
        $path = ilObjMediaObject::_lookupItemPath($mobId, false, true);

        $tokenMaxLifetimeInSeconds = ilWACSignedPath::getTokenMaxLifetimeInSeconds();
        ilWACSignedPath::setTokenMaxLifetimeInSeconds($ttl);

        $url = ILIAS_HTTP_PATH . substr(ilWACSignedPath::signFile($path), 1);

        ilWACSignedPath::setTokenMaxLifetimeInSeconds($tokenMaxLifetimeInSeconds);
        return $url;
    }

    /**
     * Save uploaded media item.
     * @param ?string $content (null for uploaded file)
     * @return ilObjMediaObject
     */
    protected function saveMob($content = null)
    {
        $filename = $content == null
            ? ilObjMediaObject::fixFilename($_FILES[self::PROP_INPUT_FILE]["name"])
            : "freetext.htm";

        // Create media object.
        $mob = new ilObjMediaObject();
        $mob->setTitle($filename);
        $mob->setDescription("");
        $mob->create();

        // Determine and create mob directory, move uploaded file to directory.
        $mob->createDirectory();
        $mob_dir = ilObjMediaObject::_getDirectory($mob->getId());

        $media_item = new ilMediaItem();
        $mob->addMediaItem($media_item);
        $media_item->setPurpose("Standard");

        // Save file to its path.
        $path = $mob_dir . "/" . $filename;
        if ($content == null) {
            ilFileUtils::moveUploadedFile(
                $_FILES[self::PROP_INPUT_FILE]["tmp_name"],
                $filename,
                $path
            );
        } else {
            file_put_contents($path, $content);
        }

        // Set real meta and object data.
        $media_item->setFormat(ilObjMediaObject::getMimeType($path));
        $media_item->setLocation($filename);
        $media_item->setLocationType("LocalFile");

        ilObjMediaObject::renameExecutables($mob_dir);
        ilMediaSvgSanitizer::sanitizeDir($mob_dir);
        $mob->update();

        return $mob;
    }

    /**
     * Get the format of the document to use with Nolej API.
     * @param int $mobId
     * @return string format (empty if not valid)
     */
    protected function getMobFormat(int $mobId): string
    {
        $path = ilObjMediaObject::_lookupItemPath($mobId, false, false);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ilNolejAPI::TYPE_AUDIO)) {
            return self::PROP_AUDIO;
        }
        if (in_array($extension, ilNolejAPI::TYPE_VIDEO)) {
            return self::PROP_VIDEO;
        }
        if (in_array($extension, ilNolejAPI::TYPE_DOC)) {
            return self::PROP_DOC;
        }
        return "";
    }
}

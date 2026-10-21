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
 * Activities settings Form GUI class.
 *
 * @ilCtrl_isCalledBy ilNolejActivitiesFormGUI: ilObjPluginDispatchGUI, ilObjNolejGUI, ilNolejManagerGUI
 */
class ilNolejActivitiesFormGUI extends ilNolejFormGUI
{
    /**
     * Settings of every activity type.
     *
     * - "ibook": name of the checkbox (and of the setting) to include the activity in the interactive book.
     * - "number": [name prefix, language key of the label, minimum value]; the current value is stored
     *   in the setting "<prefix>_current", the maximum value is read from "<prefix>_max".
     *
     * @var array<string, array{ibook?: string, number?: array{0: string, 1: string, 2: int}}>
     */
    protected const ACTIVITY_SETTINGS = [
        "ibook" => [],
        "glossary" => [
            "ibook" => "Glossary_include_IB",
        ],
        "summary" => [
            "ibook" => "Summary_include_IB",
        ],
        "findtheword" => [
            "number" => ["FTW_number_word", "activities_ftw_words", 3],
        ],
        "dragtheword" => [
            "ibook" => "DTW_include_IB",
            "number" => ["DTW_number_word", "activities_dtw_words", 3],
        ],
        "crossword" => [
            "number" => ["CW_number_word", "activities_cw_words", 3],
        ],
        "practice" => [
            "ibook" => "Practice_include_IB",
            "number" => ["Practice_number_flashcard", "activities_practice_flashcards", 0],
        ],
        "practiceq" => [
            "ibook" => "PracticeQ_include_IB",
            "number" => ["PracticeQ_number_flashcard", "activities_practiceq_flashcards", 0],
        ],
        "grade" => [
            "ibook" => "Grade_include_IB",
            "number" => ["Grade_number_question", "activities_grade_questions", 0],
        ],
        "gradeq" => [
            "ibook" => "GradeQ_include_IB",
            "number" => ["GradeQ_number_question", "activities_gradeq_questions", 0],
        ],
        "flashcards" => [
            "number" => ["Flashcards_number_flashcard", "activities_flashcards_flashcards", 0],
        ],
        "ivideo" => [
            "number" => ["IV_number_question_perset", "activities_ivideo_questions", 0],
        ],
    ];

    /**
     * Show activities form.
     * @return void
     */
    public function showForm(): void
    {
        $this->tpl->setRightContent(
            $this->renderer->render($this->manager->getWorkflow()->withActive(5))
        );

        if ($this->status < ilNolejManagerGUI::STATUS_ANALYSIS) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("err_transcription_not_ready")));
            return;
        }

        if ($this->status < ilNolejManagerGUI::STATUS_REVISION) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("err_analysis_not_ready")));
            return;
        }

        if ($this->status == ilNolejManagerGUI::STATUS_ACTIVITIES_PENDING) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("activities_generation_start")));
            return;
        }

        $form = $this->form();
        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Save activities form.
     * @return void
     */
    public function saveForm(): void
    {
        global $DIC;

        $form = $this->form(true);

        if ($this->status == ilNolejManagerGUI::STATUS_CREATION_PENDING) {
            $this->tpl->setOnScreenMessage("info", $this->plugin->txt("activities_generation_start"));
            $this->tpl->setContent($form->getHTML());
            return;
        }

        if (!$form->checkInput()) {
            // Input not ok.
            $this->tpl->setContent($form->getHTML());
            return;
        }

        $json = $this->manager->readDocumentFile("settings.json");
        if (!$json) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_settings_file"));
            $this->tpl->setContent($form->getHTML());
            return;
        }
        $settings = json_decode($json, true);
        $availableActivities = $settings["avaible_packages"] ?? [];

        $settingsToSave = [
            "settings" => $settings["settings"],
            "avaible_packages" => $availableActivities,
            "desired_packages" => [],
        ];

        foreach ($availableActivities as $activity) {
            $useActivity = (bool) $form->getInput("activity_" . $activity);
            if (!$useActivity) {
                continue;
            }

            $settingsToSave["desired_packages"][] = $activity;

            $activitySettings = self::ACTIVITY_SETTINGS[$activity] ?? [];
            if (isset($activitySettings["ibook"])) {
                $settingsToSave["settings"][$activitySettings["ibook"]] = (bool) $form->getInput($activitySettings["ibook"]);
            }
            if (isset($activitySettings["number"])) {
                $name = $activitySettings["number"][0] . "_current";
                $settingsToSave["settings"][$name] = (int) $form->getInput($name);
            }
        }

        $success = $this->manager->writeDocumentFile(
            "settings.json",
            (string) json_encode($settingsToSave)
        );
        if (!$success) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_settings_save"));
            $this->showForm();
            return;
        }

        $this->manager->updateDocumentStatus(ilNolejManagerGUI::STATUS_ACTIVITIES_PENDING);

        $success = $this->manager->putNolejContent("settings", "settings.json");
        if (!$success) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_settings_put"));
            $this->showForm();
            return;
        }

        $ass = new ilNolejActivity($this->documentId, $DIC->user()->getId(), "activities");
        $ass->withStatus("ok")
            ->withCode(0)
            ->withErrorMessage("")
            ->withConsumedCredit(0)
            ->store();

        $this->tpl->setOnScreenMessage("success", $this->plugin->txt("activities_generation_start"));
        $this->ctrl->redirect($this, self::CMD_SHOW);
    }

    /**
     * Init activities form.
     * @param bool $usePost Set value from POST, if false load activities file
     * @return ilPropertyFormGUI
     */
    protected function form(bool $usePost = false): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->plugin->txt("activities_settings"));

        $this->manager->getNolejContent("settings", "settings.json", !$usePost);
        $json = $this->manager->readDocumentFile("settings.json");
        if (!$json) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_settings_file"));
            return $form;
        }

        $settings = json_decode($json);
        $availableActivities = $settings->avaible_packages ?? [];
        $settings = $settings->settings;

        foreach ($availableActivities as $availableActivity) {
            $activity = new ilCheckboxInputGUI(
                $this->plugin->txt("activities_" . $availableActivity),
                "activity_" . $availableActivity
            );

            if ($usePost) {
                $activity->setValueByArray($this->getPostData());
            }
            if (!$usePost) {
                $activity->setChecked(true);
            }

            $this->addActivitySettings($activity, $availableActivity, $settings, $usePost);

            $form->addItem($activity);
        }

        $form->addCommandButton(self::CMD_SAVE, $this->plugin->txt("cmd_generate"));
        $form->setFormAction($this->ctrl->getFormAction($this));

        return $form;
    }

    /**
     * Add to the checkbox of an activity the inputs of its settings.
     * @param ilCheckboxInputGUI $activity the checkbox of the activity
     * @param string $activityType type of the activity, e.g. "glossary"
     * @param object $settings settings, as read from the settings file
     * @param bool $usePost
     * @return void
     */
    protected function addActivitySettings(
        ilCheckboxInputGUI $activity,
        string $activityType,
        $settings,
        bool $usePost
    ): void {
        $activitySettings = self::ACTIVITY_SETTINGS[$activityType] ?? [];

        $ibook = null;
        if (isset($activitySettings["ibook"])) {
            $ibook = new ilCheckboxInputGUI(
                $this->plugin->txt("activities_use_in_ibook"),
                $activitySettings["ibook"]
            );
        }

        $number = null;
        $numberName = "";
        if (isset($activitySettings["number"])) {
            [$prefix, $labelKey, $minValue] = $activitySettings["number"];
            $numberName = $prefix . "_current";
            $number = new ilNumberInputGUI($this->plugin->txt($labelKey), $numberName);
            $number->allowDecimals(false);
            $number->setMinValue($minValue, true);
            $number->setMaxValue((float) $settings->{$prefix . "_max"}, true);
        }

        if ($usePost) {
            $ibook?->setValueByArray($this->getPostData());
            $number?->setValueByArray($this->getPostData());
        }
        $ibookName = $activitySettings["ibook"] ?? null;
        if (!$usePost && $ibook !== null && $ibookName !== null) {
            $ibook->setChecked((bool) $settings->{$ibookName});
        }
        if (!$usePost && $number !== null) {
            $number->setValue((string) $settings->{$numberName});
        }

        if ($ibook !== null) {
            $activity->addSubItem($ibook);
        }
        if ($number !== null) {
            $activity->addSubItem($number);
        }
    }
}

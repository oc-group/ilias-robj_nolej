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

use ILIAS\UI\Component\Listing\Workflow\Step;
use Nolej\ilTextAreaInputGUI;

/**
 * Questions Form GUI class.
 *
 * @ilCtrl_isCalledBy ilNolejQuestionsFormGUI: ilObjPluginDispatchGUI, ilObjNolejGUI, ilNolejManagerGUI
 */
class ilNolejQuestionsFormGUI extends ilNolejFormGUI
{
    /**
     * Show questions form.
     * @return void
     */
    public function showForm(): void
    {
        $this->tpl->setRightContent(
            $this->renderer->render($this->manager->getWorkflow()->withActive(3))
        );

        if ($this->status < ilNolejManagerGUI::STATUS_ANALYSIS) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("err_transcription_not_ready")));
            return;
        }

        if ($this->status < ilNolejManagerGUI::STATUS_REVISION) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("err_analysis_not_ready")));
            return;
        }

        $form = $this->form();
        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Save questions form.
     * @return void
     */
    public function saveForm(): void
    {
        $status = $this->status;

        if ($status < ilNolejManagerGUI::STATUS_REVISION) {
            $this->tpl->setContent($this->infoBox($this->plugin->txt("err_analysis_not_ready")));
            $this->tpl->setRightContent(
                $this->renderer->render($this->manager->getWorkflow()->withActive(3))
            );
            return;
        }

        $form = $this->form(true);
        if (!$form->checkInput()) {
            // Input not ok.
            $this->tpl->setContent($form->getHTML());
            $this->tpl->setRightContent(
                $this->renderer->render($this->manager->getWorkflow()->withActive(3))
            );
            return;
        }

        $this->manager->getNolejContent("questions", "questions.json");
        $json = $this->manager->readDocumentFile("questions.json");
        if (!$json) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_questions_file"));
            $this->tpl->setContent($form->getHTML());
            return;
        }

        $questions = json_decode($json);
        $questions = $questions->questions;

        $length = $form->getInput("questions_count");
        for ($i = 0; $i < $length; $i++) {
            $question = $this->readQuestion($form, $i);
            if ($question !== null) {
                $questions[$i] = $question;
            }
        }

        $success = $this->manager->writeDocumentFile(
            "questions.json",
            (string) json_encode(["questions" => $questions])
        );
        if (!$success) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_questions_save"));
            $this->showForm();
            return;
        }

        $success = $this->manager->putNolejContent("questions", "questions.json");
        $this->tpl->setOnScreenMessage(
            $success ? "success" : "failure",
            $this->plugin->txt($success ? "questions_saved" : "err_questions_put")
        );
        $this->showForm();
    }

    /**
     * Read the i-th question from the (checked) form.
     * @param ilPropertyFormGUI $form
     * @param int $i index of the question
     * @return array<string, mixed>|null null if the question is not in the form.
     */
    protected function readQuestion(ilPropertyFormGUI $form, int $i): ?array
    {
        $id = $form->getInput("question_{$i}_id");
        if (empty($id)) {
            return null;
        }

        $questionType = $form->getInput("question_{$i}_type");

        $enable = false;
        $useForGrading = false;
        if ($questionType == "open") {
            $enable = (bool) $form->getInput("question_{$i}_enable");
        }
        if ($questionType != "open") {
            $useForGrading = (bool) $form->getInput("question_{$i}_enable");
        }

        $answer = $questionType == "hoq"
            ? ""
            : $form->getInput("question_{$i}_answer");

        $question = $questionType == "tf"
            ? ""
            : $form->getInput("question_{$i}_question");

        $distractorsCount = $form->getInput("question_{$i}_distractors");
        $distractors = [];
        for ($j = 0; $j < $distractorsCount; $j++) {
            $distractor = $form->getInput("question_{$i}_distractor_{$j}");
            if (!empty($distractor)) {
                $distractors[] = $distractor;
            }
        }
        $selectedDistractor = $questionType == "tf"
            ? $form->getInput("question_{$i}_use_distractor")
            : "";

        return [
            "id" => $id,
            "explanation" => "false",
            "enable" => $enable,
            "answer" => $answer,
            "use_for_grading" => $useForGrading,
            "question" => $question,
            "question_type" => $questionType,
            "distractors" => $distractors,
            "selected_distractor" => $selectedDistractor,
        ];
    }

    /**
     * Init questions form.
     * @param bool $usePost Set value from POST, if false load questions file
     * @return ilPropertyFormGUI
     */
    protected function form($usePost = false): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setTitle($this->plugin->txt("review_questions"));

        // Download questions.
        $this->manager->getNolejContent("questions", "questions.json");
        $json = $this->manager->readDocumentFile("questions.json");
        if (!$json) {
            $this->tpl->setOnScreenMessage("failure", $this->plugin->txt("err_questions_file"));
            return $form;
        }

        $questionTypeFilter = $_GET["filter_type"] ?? "";
        $questionTypes = [];

        $questions = json_decode($json);
        $questions = $questions->questions;

        $length = count($questions);
        $length_input = new ilHiddenInputGUI("questions_count");
        $length_input->setValue((string) $length);
        $form->addItem($length_input);
        for ($i = 0; $i < $length; $i++) {
            // Counter.
            if (!isset($questionTypes[$questions[$i]->question_type])) {
                $questionTypes[$questions[$i]->question_type] = 0;
            }
            $questionTypes[$questions[$i]->question_type] += 1;

            // Filter by question type.
            if (!empty($questionTypeFilter) && $questionTypeFilter != $questions[$i]->question_type) {
                continue;
            }

            $this->addQuestionItems($form, $questions[$i], $i, $usePost);
        }

        // Use view control to filter questions.
        $target = $this->ctrl->getLinkTarget($this, self::CMD_SHOW);
        $all = sprintf("%s (%d)", $this->plugin->txt("questions_type_all"), $length);
        $actions = [
            $all => $target,
        ];

        $selectedAction = $all;
        foreach ($questionTypes as $type => $count) {
            $title = $this->plugin->txt("questions_type_{$type}");
            $key = "{$title} ({$count})";
            $actions[$key] = "{$target}&filter_type={$type}";
            if ($type == $questionTypeFilter) {
                $selectedAction = $key;
            }
        }
        $viewControl = $this->factory->viewControl()
            ->mode($actions, $this->plugin->txt("questions_question_type"))
            ->withActive($selectedAction);

        $form->setDescription($this->renderer->render([$this->factory->divider()->horizontal(), $viewControl]));

        $this->ctrl->setParameter($this, "filter_type", $questionTypeFilter);
        $form->addCommandButton(self::CMD_SAVE, $this->lng->txt("save"));
        $form->setFormAction($this->ctrl->getFormAction($this));

        return $form;
    }

    /**
     * Add to the form the inputs of a question.
     * @param ilPropertyFormGUI $form
     * @param object $question the question, as read from the questions file
     * @param int $i index of the question
     * @param bool $usePost
     * @return void
     */
    protected function addQuestionItems(ilPropertyFormGUI $form, $question, int $i, bool $usePost): void
    {
        $type = $question->question_type;

        $section = new ilFormSectionHeaderGUI();
        $section->setTitle(sprintf($this->plugin->txt("questions_n"), $i + 1));
        $form->addItem($section);

        $id = new ilHiddenInputGUI("question_{$i}_id");
        $id->setValue($question->id);
        $form->addItem($id);

        // Text of the question.
        $questionInput = null;
        if ($type != "tf") {
            $questionInput = new ilTextAreaInputGUI($this->plugin->txt("questions_question"), "question_{$i}_question");
            if ($type == "ftb") {
                $questionInput->addRegex("/_{4}/", $this->plugin->txt("err_ftb_missing_blank"));
            }
            $form->addItem($questionInput);
        }

        // Question type.
        $questionType = new ilHiddenInputGUI("question_{$i}_type");
        $questionType->setValue($type);
        $form->addItem($questionType);

        $questionTypeLabel = new ilNonEditableValueGUI(
            $this->plugin->txt("questions_question_type"),
            "question_{$i}_type_label"
        );
        $questionTypeLabel->setValue($this->plugin->txt("questions_type_" . $type));
        $form->addItem($questionTypeLabel);

        // Enable question.
        $enable = new ilCheckboxInputGUI(
            $this->plugin->txt($type == "open" ? "questions_enable" : "questions_use_for_grading"),
            "question_{$i}_enable"
        );
        $form->addItem($enable);

        // Text of the answer.
        $answer = null;
        if ($type != "hoq") {
            $answer = new ilTextAreaInputGUI(
                $this->plugin->txt($type == "tf" ? "questions_answer_true" : "questions_answer"),
                "question_{$i}_answer"
            );
            if ($type == "tf") {
                $form->addItem($answer);
            }
            if ($type != "tf") {
                $enable->addSubItem($answer);
            }
        }

        $this->addDistractorItems($form, $enable, $question, $i, $usePost);

        if ($usePost) {
            $enable->setValueByArray($this->getPostData());
            $answer?->setValueByArray($this->getPostData());
            $questionInput?->setValueByArray($this->getPostData());
            return;
        }

        $enable->setChecked($type == "open" ? $question->enable : $question->use_for_grading);
        $answer?->setValue($question->answer);
        $questionInput?->setValue($question->question);
    }

    /**
     * Add to the form the distractors of a question, and the distractor choice of true/false questions.
     * @param ilPropertyFormGUI $form
     * @param ilCheckboxInputGUI $enable the enable checkbox of the question
     * @param object $question the question, as read from the questions file
     * @param int $i index of the question
     * @param bool $usePost
     * @return void
     */
    protected function addDistractorItems(
        ilPropertyFormGUI $form,
        ilCheckboxInputGUI $enable,
        $question,
        int $i,
        bool $usePost
    ): void {
        $type = $question->question_type;

        $distractorsCount = count($question->distractors);
        $distractors = new ilHiddenInputGUI("question_{$i}_distractors");
        $distractors->setValue((string) $distractorsCount);
        $form->addItem($distractors);

        for ($j = 0; $j < $distractorsCount; $j++) {
            $distractor = new ilTextAreaInputGUI(
                $j == 0 ? $this->plugin->txt($type == "tf" ? "questions_answer_false" : "questions_distractors") : "",
                "question_{$i}_distractor_{$j}"
            );
            if ($type == "tf") {
                $form->addItem($distractor);
            }
            if ($type != "tf") {
                $enable->addSubItem($distractor);
            }
            if ($usePost) {
                $distractor->setValueByArray($this->getPostData());
                continue;
            }
            $distractor->setValue($question->distractors[$j]);
        }

        // Choose distractor.
        if ($type != "tf") {
            return;
        }

        $useDistractor = new ilSelectInputGUI(
            $this->plugin->txt("questions_answer_distractor"),
            "question_{$i}_use_distractor"
        );
        $useDistractor->setOptions([
            "" => $this->plugin->txt("questions_answer_true"),
            "useFalse" => $this->plugin->txt("questions_answer_false"),
        ]);
        $enable->addSubItem($useDistractor);
        if ($usePost) {
            $useDistractor->setValueByArray($this->getPostData());
            return;
        }
        $useDistractor->setValue($question->selected_distractor);
    }
}

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

use ILIAS\UI\Component\Input\Container\Form\Form;
use srag\Plugins\H5P\Content\ContentEditorHelper;
use srag\Plugins\H5P\Content\Form\EditContentFormBuilder;
use srag\Plugins\H5P\Content\Form\EditContentFormProcessor;
use srag\Plugins\H5P\Content\Form\IPostProcessorAware;
use srag\Plugins\H5P\Content\IContent;
use srag\Plugins\H5P\IContainer;
use srag\Plugins\H5P\IRepositoryFactory;
use srag\Plugins\H5P\ITranslator;
use srag\Plugins\H5P\TemplateHelper;

/**
 * H5P integration class.
 *
 * Requires the H5P repository plugin (id xhfp) 7.x for ILIAS 10. The H5P classes used here are
 * loaded by the H5P plugin itself; this class must therefore only be loaded after
 * ilNolejPlugin::isH5PInstalled() returned true (it is never loaded by the plugin class or by
 * the GlobalScreen / renderer hooks).
 *
 * @ilCtrl_isCalledBy ilNolejH5PIntegrationGUI: ilObjPluginDispatchGUI, ilObjNolejGUI
 */
class ilNolejH5PIntegrationGUI
{
    use ContentEditorHelper;
    use TemplateHelper;

    /** @var string edit h5p activity */
    public const CMD_EDIT = "edit";

    /** @var string update h5p activity */
    public const CMD_UPDATE = "update";

    protected ilCtrl $ctrl;

    protected ilGlobalTemplateInterface $tpl;

    protected \ILIAS\UI\Factory $factory;

    protected IContainer $h5p_container;

    protected IRepositoryFactory $repositories;

    protected ITranslator $translator;

    protected \Psr\Http\Message\ServerRequestInterface $request;

    protected ilToolbarGUI $toolbar;

    protected \ILIAS\Refinery\Factory $refinery;

    protected ilLanguage $lng;

    protected ilTabsGUI $tabs;

    protected \ILIAS\HTTP\Wrapper\RequestWrapper $query;

    protected ilObjNolejGUI $obj_gui;

    protected ilNolejPlugin $plugin;

    /**
     * Constructor.
     * @param ilObjNolejGUI $obj_gui
     * @throws LogicException if H5P is not installed.
     */
    public function __construct(ilObjNolejGUI $obj_gui)
    {
        global $DIC;

        if (!ilNolejPlugin::isH5PInstalled()) {
            throw new LogicException("You cannot use this plugin without installing H5P plugin first.");
        }

        /** @var ilH5PPlugin $h5p_plugin */
        $h5p_plugin = ilNolejPlugin::getH5PPlugin();
        $this->h5p_container = $h5p_plugin->getContainer();
        $this->repositories = $this->h5p_container->getRepositoryFactory();
        $this->translator = $this->h5p_container->getTranslator();

        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->template = $DIC->ui()->mainTemplate(); // For TemplateHelper.
        $this->renderer = $DIC->ui()->renderer();
        $this->factory = $DIC->ui()->factory();
        $this->request = $DIC->http()->request();
        $this->query = $DIC->http()->wrapper()->query();
        $this->refinery = $DIC->refinery();
        $this->toolbar = $DIC->toolbar();
        $this->lng = $DIC->language();
        $this->tabs = $DIC->tabs();

        $this->obj_gui = $obj_gui;
        $this->plugin = ilNolejPlugin::getInstance();
    }

    /**
     * Handles all commmands, $cmd = functionName()
     * @throws ilException if command is not known
     * @return void
     */
    public function executeCommand(): void
    {
        // Check H5P plugin is active.
        if (!ilNolejPlugin::isH5PActive()) {
            $this->tpl->setContent(
                $this->renderer->render(
                    $this->factory->messageBox()->failure($this->plugin->txt("err_h5p_not_active"))
                )
            );
            return;
        }

        $cmd = $this->ctrl->getCmd();
        switch ($cmd) {
            case self::CMD_EDIT:
            case self::CMD_UPDATE:
                $content = $this->getContentByRequest();
                if (null === $content) {
                    $this->tpl->setContent(
                        $this->renderer->render(
                            $this->factory->messageBox()->failure($this->plugin->txt("err_h5p_content"))
                        )
                    );
                    return;
                }

                // Needed for ajax loader.
                $this->ctrl->setParameterByClass(
                    ilH5PAjaxEndpointGUI::class,
                    "ref_id",
                    (string) $this->obj_gui->getNolejObject()->getRefId()
                );

                if ($cmd === self::CMD_EDIT) {
                    $this->edit($content);
                    break;
                }
                $this->update($content);
                break;

            default:
                throw new ilException("Unknown command: '$cmd'");
        }
    }

    /**
     * Set back target.
     * @return void
     */
    protected function setTabs(): void
    {
        $this->tabs->clearTargets();

        $this->ctrl->setParameter($this->obj_gui, "type", $this->getRequestedType());
        $this->tabs->setBackTarget(
            $this->lng->txt("back"),
            $this->ctrl->getLinkTarget($this->obj_gui, ilObjNolejGUI::CMD_CONTENT_SHOW)
        );
    }

    /**
     * Get the requested H5P activity type (from the query).
     * @return string empty string if not requested.
     */
    protected function getRequestedType(): string
    {
        if (!$this->query->has("type")) {
            return "";
        }
        return $this->query->retrieve("type", $this->refinery->kindlyTo()->string());
    }

    /**
     * Get the h5p content element using the current object and the requested type.
     * @return ?IContent
     */
    protected function getContentByRequest(): ?IContent
    {
        if (!$this->query->has("ref_id") || !$this->query->has("type")) {
            return null;
        }

        $this->ctrl->saveParameterByClass(ilObjNolejGUI::class, "ref_id");
        $this->ctrl->saveParameterByClass(ilObjNolejGUI::class, "type");

        $object = new ilObjNolej(
            $this->query->retrieve("ref_id", $this->refinery->kindlyTo()->int())
        );
        $contentId = $object->getContentIdOfType($this->getRequestedType());

        // Check H5P content ID.
        if ($contentId == -1) {
            return null;
        }

        return $this->repositories->content()->getContent($contentId);
    }

    /**
     * Get the HTML of an H5P activity.
     * @param int $contentId
     * @param string $type
     * @param bool $editable
     * @return string html
     */
    public function getHTML(int $contentId, string $type = "", bool $editable = false): string
    {
        $this->tpl->addCss(ilNolejPlugin::PLUGIN_DIR . "/css/nolej.css");

        // Check H5P plugin is active.
        if (!ilNolejPlugin::isH5PActive()) {
            return $this->renderer->render(
                $this->factory->messageBox()->failure($this->plugin->txt("err_h5p_not_active"))
            );
        }

        // Check H5P content ID.
        if ($contentId == -1) {
            return $this->renderer->render(
                $this->factory->messageBox()->failure($this->plugin->txt("err_h5p_content"))
            );
        }

        $content = $this->repositories->content()->getContent($contentId);

        if (null === $content) {
            return $this->renderer->render(
                $this->factory->messageBox()->failure($this->plugin->txt("err_h5p_content"))
            );
        }

        if ($editable) {
            $this->ctrl->setParameter($this, "type", $type);
            $this->ctrl->setParameter($this, "ref_id", $this->obj_gui->getNolejObject()->getRefId());
            $this->toolbar->addComponent(
                $this->factory->button()->standard(
                    $this->plugin->txt("edit_h5p"),
                    $this->ctrl->getLinkTarget($this, self::CMD_EDIT)
                )
            );
        }

        $component = $this->h5p_container->getComponentFactory()
            ->content($content)
            ->withLoadingMessage(
                ilNolejManagerGUI::glyphicon("refresh gly-spin") . $this->plugin->txt("content_loading")
            );

        return $this->renderer->render($component);
    }

    /**
     * Import H5P file.
     * The file is moved by H5P into its own temporary storage.
     * @param string $filepath path of the .h5p file, relative to the current working directory
     * @param string $type
     * @return int content id, -1 if the package is not valid.
     */
    public function importFromPath(string $filepath, string $type): int
    {
        $absolute_path = realpath($filepath);
        if ($absolute_path === false) {
            return -1;
        }

        $h5p_kernel = $this->getKernel();

        $file = ilH5PEditorStorage::saveFileTemporarily($absolute_path, true);
        if (!is_object($file)) {
            return -1;
        }

        $file_upload_communicator = $this->h5p_container->getFileUploadCommunicator();
        $file_upload_communicator->setUploadPath("{$file->dir}/{$file->fileName}");

        $h5p_storage = $this->h5p_container->getKernelStorage();
        $h5p_validator = $this->h5p_container->getKernelValidator();

        if (!$h5p_validator->isValidPackage()) {
            ilH5PEditorStorage::removeTemporarilySavedFiles($file_upload_communicator->getUploadPath());
            return -1;
        }

        $metadata = (array) $h5p_kernel->mainJsonData;
        $metadata["title"] = $this->plugin->txt("activities_{$type}");
        $metadata["in_workspace"] = false;
        $metadata["obj_id"] = $this->obj_gui->getNolejObject()->getId();
        $metadata["parent_type"] = ilNolejPlugin::PLUGIN_ID;

        // The kernel documents the first parameter of savePackage() as null, but it takes the content.
        /** @psalm-suppress InvalidArgument */
        // @phpstan-ignore argument.type
        $h5p_storage->savePackage([
            "metadata" => $metadata,
        ]);

        ilH5PEditorStorage::removeTemporarilySavedFiles($file_upload_communicator->getUploadPath());

        return (int) $h5p_storage->contentId;
    }

    /**
     * Show the editor of the given h5p activity.
     * @param IContent $content
     * @return void
     */
    public function edit(IContent $content): void
    {
        $this->setTabs();
        $this->render($this->getEditContentForm(self::CMD_UPDATE, $content));
    }

    /**
     * Update the h5p activity.
     * @param IContent $content
     * @return void
     */
    public function update(IContent $content): void
    {
        $this->runFormProcessor(
            $this->getEditContentFormProcessor(
                $this->getEditContentForm(self::CMD_UPDATE, $content)
            )
        );
    }

    /**
     * Executes the given form processor and redirects to the content on success.
     * @param IPostProcessorAware $form_processor
     * @return void
     */
    protected function runFormProcessor(IPostProcessorAware $form_processor): void
    {
        if ($form_processor->processForm()) {
            $this->ctrl->redirectByClass(
                [ilObjPluginDispatchGUI::class, ilObjNolejGUI::class],
                ilObjNolejGUI::CMD_CONTENT_SHOW
            );
        }

        $this->render($form_processor->getProcessedForm());
    }

    /**
     * Return the editor form.
     * @param string $command
     * @param IContent $content
     * @return Form
     */
    protected function getEditContentForm(string $command, IContent $content): Form
    {
        $builder = new EditContentFormBuilder(
            $this->translator,
            $this->factory->input()->container()->form(),
            $this->factory->input()->field(),
            $this->h5p_container->getComponentFactory(),
            $this->refinery,
            $this->getContentEditorData($content->getContentId())
        );

        return $builder->getForm(
            $this->ctrl->getFormAction($this, $command)
        );
    }

    /**
     * Return the h5p editor processor.
     * @param Form $edit_form
     * @return IPostProcessorAware
     */
    protected function getEditContentFormProcessor(Form $edit_form): IPostProcessorAware
    {
        return new EditContentFormProcessor(
            $this->repositories->content(),
            $this->repositories->library(),
            $this->h5p_container->getKernel(),
            $this->h5p_container->getEditor(),
            $this->request,
            $edit_form,
            $this->obj_gui->getNolejObject()->getId(),
            ilNolejPlugin::PLUGIN_ID,
            false
        );
    }

    /**
     * Get the h5p kernel.
     * @see ContentEditorHelper::getKernel
     * @return \H5PCore
     */
    protected function getKernel(): \H5PCore
    {
        return $this->h5p_container->getKernel();
    }
}

<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Bizproc\Api\Request\WorkflowStateService\GetAverageWorkflowDurationRequest;
use Bitrix\Bizproc\Api\Service\WorkflowStateService;
use Bitrix\Bizproc\Internal\Service\Document\DocumentsResolver;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateUserOptionTable;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Errorable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;

class BizprocWorkflowStartList extends CBitrixComponent implements Errorable, Controllerable
{
	private const SHOW_IN_STARTER = 'show_in_starter';
	private string $gridId = 'bizproc_workflow_start_list';
	private string $filterId = 'bizproc_workflow_start_list_filter';

	private ErrorCollection $errorCollection;

	public function __construct($component = null)
	{
		parent::__construct($component);

		$this->errorCollection = new ErrorCollection();
	}

	public static function pinAction(int $templateId, CurrentUser $user): bool
	{
		if (!$templateId)
		{
			return false;
		}

		$userId = $user->getId();
		if (!$userId)
		{
			return false;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		$result = WorkflowTemplateUserOptionTable::addOption($templateId, $userId, WorkflowTemplateUserOptionTable::PINNED);

		return $result->isSuccess();
	}

	public static function unpinAction(int $templateId, CurrentUser $user): bool
	{
		if (!$templateId)
		{
			return false;
		}

		$userId = $user->getId();
		if (!$userId)
		{
			return false;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		$result = WorkflowTemplateUserOptionTable::deleteOption($templateId, $userId, WorkflowTemplateUserOptionTable::PINNED);

		return $result->isSuccess();
	}

	public function addErrors(array $errors): self
	{
		$this->errorCollection->add($errors);

		return $this;
	}

	public function getErrorByCode($code): \Bitrix\Main\Error
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	public function getErrors(): array
	{
		return $this->errorCollection->toArray();
	}

	public function setError(\Bitrix\Bizproc\Error $error): self
	{
		$this->errorCollection->setError($error);

		return $this;
	}

	public function hasErrors(): bool
	{
		return !$this->errorCollection->isEmpty();
	}

	public function configureActions(): array
	{
		return [];
	}

	public function onPrepareComponentParams($arParams)
	{
		$arParams['signedDocumentType'] = htmlspecialcharsback($arParams['signedDocumentType'] ?? '');
		$arParams['signedDocumentId'] = htmlspecialcharsback($arParams['signedDocumentId'] ?? '');

		if (Loader::includeModule('bizproc'))
		{
			$arParams['signedDocuments'] = $this->normalizeSignedDocuments($arParams['signedDocuments'] ?? []);
			$arParams['documents'] = $this->normalizeDocuments($arParams['documents'] ?? []);
		}
		else
		{
			$arParams['signedDocuments'] = [];
			$arParams['documents'] = [];
		}

		return $arParams;
	}

	public function executeComponent()
	{
		global $APPLICATION;
		$APPLICATION->SetTitle(Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_TITLE'));

		$this->init();

		if ($this->hasErrors())
		{
			return $this->includeComponentTemplate('error');
		}

		$this->fillGridInfo();
		$this->fillGridData();
		$this->fillDocumentData();

		$singleDocument = $this->getSingleDocumentContext();
		$this->arResult['documentType'] = $singleDocument['documentType'] ?? null;
		$this->arResult['documentId'] = $singleDocument['documentId'] ?? null;
		$this->arResult['categoryId'] = $singleDocument['categoryId'] ?? null;
		$this->arResult['canEdit'] = $this->canEditSingleDocument();
		$this->arResult['bizprocEditorUrl'] = $singleDocument
			? $singleDocument['editorUrl']
			: ''
		;
		$this->arResult['bizprocNewEditorUrl'] = '/bizprocdesigner/editor?ID=#ID#';

		$this->arResult['signedDocumentType'] = $singleDocument
			? $singleDocument['signedDocumentType']
			: ''
		;
		$this->arResult['signedDocumentId'] = $singleDocument
			? $singleDocument['signedDocumentId']
			: ''
		;

		$this->addToolbar();

		return $this->includeComponentTemplate();
	}

	private function init(): void
	{
		$this->checkModules();

		if (!$this->hasErrors())
		{
			$this->initDocuments();
		}
	}

	private function checkModules(): void
	{
		if (!Loader::includeModule('bizproc'))
		{
			$this->setError(ErrorMessage::MODULE_NOT_INSTALLED->getError());
		}
	}

	private function initDocuments(): void
	{
		$documents = $this->buildDocuments();
		if (empty($documents))
		{
			$this->setError(ErrorMessage::DOCUMENT_TYPE_ERROR->getError());

			return;
		}

		$allowedDocuments = $this->filterDocumentsByRights($documents);

		if (empty($allowedDocuments))
		{
			$errorMsg = ErrorMessage::TEMPLATE_NO_PRERMISSIONS->getError();
			$this->setError($errorMsg);

			return;
		}

		$documentContexts = $this->buildDocumentContexts($allowedDocuments);

		$this->arResult['documents'] = $documentContexts;
		$this->gridId = $this->buildGridId($documentContexts);
		$this->filterId = $this->buildFilterId($documentContexts);
	}

	private function canEditSingleDocument(): bool
	{
		$singleDocument = $this->getSingleDocumentContext();
		if ($singleDocument === null)
		{
			return false;
		}

		return $singleDocument['canEdit'];
	}

	private function canStartWorkflow(array $documentId): bool
	{
		return CBPDocument::canUserOperateDocument(
			CBPCanUserOperateOperation::StartWorkflow,
			$this->getCurrentUserId(),
			$documentId,
		);
	}

	private function getCurrentUserId(): int
	{
		return \Bitrix\Main\Engine\CurrentUser::get()->getId();
	}

	private function normalizeDocuments(mixed $documents): array
	{
		if (!is_array($documents))
		{
			return [];
		}

		return $this->normalizeResolvedDocuments(['documents' => $documents]);
	}

	private function normalizeSignedDocuments(mixed $documents): array
	{
		if (!is_array($documents))
		{
			return [];
		}

		return $this->normalizeResolvedDocuments(['signedDocuments' => $documents]);
	}

	private function normalizeResolvedDocuments(array $payload): array
	{
		$result = [];
		foreach ($this->resolveDocumentsFromPayload($payload, false) as $resolvedDocument)
		{
			$complexDocumentType = $resolvedDocument->complexDocumentType;
			$complexDocumentId = $resolvedDocument->complexDocumentId;
			if ($complexDocumentId === null)
			{
				continue;
			}

			$result[implode('@', [
				$complexDocumentType->moduleId,
				$complexDocumentType->entity,
				$complexDocumentType->type,
			])] = [
				'documentType' => $complexDocumentType->toArray(),
				'documentId' => $complexDocumentId->toArray(),
			];
		}

		return array_values($result);
	}

	private function buildSignedDocuments(): array
	{
		$signedDocuments = $this->arParams['signedDocuments'] ?? [];
		if ($signedDocuments === [])
		{
			return [];
		}

		return $signedDocuments;
	}

	private function resolveDocumentsFromPayload(array $payload, bool $collectErrors = true): array
	{
		$result = (new DocumentsResolver())->resolveUniqueDocumentTypesFromPayload($payload);
		if (!$result->isSuccess())
		{
			if ($collectErrors)
			{
				$this->addErrors($result->getErrors());
			}

			return [];
		}

		return $result->getDocuments()?->documents ?? [];
	}

	private function fillGridInfo(): void
	{
		$this->arResult['gridId'] = $this->gridId;
		$this->arResult['gridColumns'] = $this->getGridColumns();
		$this->arResult['pageNavigation'] = $this->getPageNavigation();
	}

	private function getGridColumns(): array
	{
		return [
			[
				'id' => 'PIN',
				'name' => '',
				'default' => true,
				'class' => 'bizproc-workflow-start-list-grid-header-pin',
				'resizeable' => false,
			],
			[
				'id' => 'NAME',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_NAME'),
				'default' => true,
			],
			[
				'id' => 'START',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_START'),
				'default' => true,
			],
			[
				'id' => 'IN_PROGRESS',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_IN_PROGRESS'),
				'default' => true,
			],
			[
				'id' => 'LAST_ACTION',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_LAST_ACTION'),
				'default' => true,
			],
		];
	}

	private function getPageNavigation(): \Bitrix\Main\UI\PageNavigation
	{
		$options = new \Bitrix\Main\Grid\Options($this->gridId);
		$navParams = $options->GetNavParams();

		$pageNavigation = new \Bitrix\Main\UI\PageNavigation($this->gridId);
		$pageNavigation->setPageSize($navParams['nPageSize'])->initFromUri();

		return $pageNavigation;
	}

	private function fillGridData(): void
	{
		$documents = $this->getDocumentContexts();
		if (empty($documents))
		{
			$this->arResult['gridData'] = [];

			return;
		}

		$resultCollection = $this->buildTemplateQuery($documents)->fetchCollection();

		$jsHandlerStart = "BX.Bizproc.Component.WorkflowStartList.Instance.startWorkflow(event, '%s', '%s', %s, %s);";

		$gridData = [];
		$templateIds = $resultCollection->getIdList();
		$templateSectionsMap = $this->getTemplateSectionsMap($templateIds);
		$templateTriggerSectionsMap = $this->getTemplateTriggerSectionsMap($templateIds);
		$documentsByTypeKey = $this->buildDocumentsByTypeKey($documents);
		foreach ($resultCollection as $template)
		{
			$templateId = $template->getId();

			$instancesView = new \Bitrix\Bizproc\UI\WorkflowTemplateInstancesView($templateId);
			$templateStartData = $this->resolveTemplateStartData(
				$template,
				$documents,
				$templateSectionsMap[$templateId] ?? [],
				$templateTriggerSectionsMap[$templateId] ?? [],
			);

			$triggerType = $templateStartData['triggerType'];
			$documentTypeKeysJs = \Bitrix\Main\Web\Json::encode($templateStartData['documentTypeKeys']);
			$preferredStartDocumentTypeKeyJs = \Bitrix\Main\Web\Json::encode(
				$templateStartData['preferredStartDocumentTypeKey']
			);
			$templateDocumentTypeKeyJs = \Bitrix\Main\Web\Json::encode($templateStartData['templateDocumentTypeKey']);
			$startHandler = null;
			if ($this->hasResolvedStartDocument($templateStartData))
			{
				$startHandler = sprintf(
					$jsHandlerStart,
					$templateId,
					$triggerType,
					$documentTypeKeysJs,
					$preferredStartDocumentTypeKeyJs,
				);
			}
			$actions = [];
			if ($this->hasResolvedEditDocument($templateStartData, $documentsByTypeKey))
			{
				$actions[] = [
					'text' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_ROW_ACTION_EDIT'),
					'onclick' => "BX.Bizproc.Component.WorkflowStartList.Instance.editTemplate(event, {$templateId}, '" . \CUtil::JSEscape((string)$template->getType()) . "', {$documentTypeKeysJs}, {$templateDocumentTypeKeyJs});",
				];
			}
			$gridData[] = [
				'id' => $templateId,
				'columns' => [
					'PIN' => '',
					'NAME' => $this->renderTemplateName($template, $startHandler),
					'START' => $this->renderStartButton($startHandler, $templateId),
					'IN_PROGRESS' => $this->renderInProgress($instancesView),
					'LAST_ACTION' => \CBPViewHelper::formatDateTime($instancesView->getLastActivity()),
				],
				'data' => [
					'NAME' => $template->getName(),
				],
				'actions' => $actions,
				'cellActions' => [
					'PIN' => $this->getPinAction($template),
				],
			];
		}

		$this->arResult['gridData'] = $gridData;
	}

	private function buildTemplateQuery(array $documents): \Bitrix\Main\ORM\Query\Query
	{
		$canUseCategoryPreset = $this->canUseCategoryPreset($documents);
		$filterData = $this->getFilterData($canUseCategoryPreset);

		$result =
			WorkflowTemplateTable::query()
				->setSelect(['ID', 'NAME', 'DESCRIPTION', 'TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
				->registerRuntimeField(
					'SECTION',
					new \Bitrix\Main\ORM\Fields\Relations\Reference(
						'SECTION',
						WorkflowTemplateSectionTable::class,
						\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.TEMPLATE_ID')
					)
				)
				->registerRuntimeField(
					'PIN',
					new Reference(
						'PIN',
						WorkflowTemplateUserOptionTable::class,
						Join::on('this.ID', 'ref.TEMPLATE_ID')
							->where('ref.OPTION_CODE', WorkflowTemplateUserOptionTable::PINNED)
							->where('ref.USER_ID', $this->getCurrentUserId()),
						['join_type' => 'LEFT']
					)
				)
		;

		$order = array_merge(['PIN.ID' => 'DESC'], $this->getGridOrder());
		$result->setOrder($order);

		$documentsFilter = \Bitrix\Main\ORM\Query\Query::filter()->logic('or');
		foreach ($documents as $index => $document)
		{
			$documentsFilter->where($this->buildDocumentFilter($result, $document, $index, $filterData));
		}

		return $result->where($documentsFilter);
	}

	private function getPinAction($template): array
	{
		$actionClass = [
			\Bitrix\Main\Grid\CellActions::PIN,
		];
		$pin = $template->sysGetRuntime('PIN');
		if ($pin)
		{
			$actionClass[] = \Bitrix\Main\Grid\CellActionState::ACTIVE;
		}
		$gridId = $this->gridId;

		return [
			[
				'class' => $actionClass,
				'events' => [
					'click' => "BX.Bizproc.Component.WorkflowStartList.changePin.bind(BX.Bizproc.Component.WorkflowStartList, {$template->getId()}, '$gridId')",
				],
			],
		];
	}

	private function renderTemplateName(\Bitrix\Bizproc\Workflow\Template\Tpl $template, ?string $handler): string
	{
		static $workflowStateService;
		$workflowStateService ??= new WorkflowStateService();

		$description = trim((string)$template->getDescription()); // description can be null
		if ($description === '')
		{
			$description = Loc::getMessage('BIZPROC_WORKFLOW_START_EMPTY_DESCRIPTION_1');
		}

		$templateDescriptionElement = sprintf(
			'<span data-hint="%s" class="ui-hint"></span>',
			htmlspecialcharsbx($description)
		);

		$templateName = htmlspecialcharsbx($template->getName()) . $templateDescriptionElement;
		$templateNameElement =
			$handler === null
				? sprintf('<span>%s</span>', $templateName)
				: sprintf(
					'<a class="ui-btn-link" onclick="%s" href="#">%s</a>',
					htmlspecialcharsbx($handler),
					$templateName
				)
		;

		$duration = $workflowStateService->getAverageWorkflowDuration(
			new GetAverageWorkflowDurationRequest($template->getId())
		)->getRoundedAverageDuration();

		$durationText = $duration !== null
			? \Bitrix\Bizproc\UI\Helpers\DurationFormatter::format($duration)
			: Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_NO_DATA')
		;

		$averageTimeElement = sprintf(
			'<div class="%s">%s</div>',
			'bizproc-workflow-start-list-grid-template-average-time',
			Loc::getMessage(
				'BIZPROC_WORKFLOW_START_LIST_AVERAGE_WAITING_TIME_MSGVER_1',
				[
					'#TIME#' => '<b>' . $durationText . '</b>',
				]
			),
		);

		return sprintf(
			'<div class="bizproc-workflow-start-list-grid-template-name-wrapper">%s</div>',
			$templateNameElement . $averageTimeElement,
		);
	}

	private function renderStartButton(?string $handler, int $templateId): string
	{
		$buttonClass = 'ui-btn ui-btn-success ui-btn-round ui-btn-xs ui-btn ui-btn-no-caps';
		$buttonAttributes = '';
		if ($handler === null)
		{
			$buttonClass .= ' ui-btn-disabled';
			$buttonAttributes = 'disabled';
		}
		else
		{
			$buttonAttributes = 'onclick="' . htmlspecialcharsbx($handler) . '"';
		}

		return sprintf(
			'
				<div class="bizproc-workflow-start-list-column-start">
					<button
						class="%s"
						%s
					>%s</button>
					<div
						class="bizproc-workflow-start-list-column-start-counter-wrapper"
						data-role="template-%s-counter"
					></div>
				</div>
			',
			$buttonClass,
			$buttonAttributes,
			Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_START_BUTTON'),
			$templateId,
		);
	}

	private function renderInProgress(\Bitrix\Bizproc\UI\WorkflowTemplateInstancesView $view): string
	{
		$viewParam = htmlspecialcharsbx(\Bitrix\Main\Web\Json::encode($view));

		return <<<HTML
			<div data-role="wt-progress-{$view->getTplId()}" data-widget="{$viewParam}">
				<script>
					BX.ready(() => {
						BX.Bizproc.Workflow.Instances.Widget.renderTo(
							document.querySelector('[data-role="wt-progress-{$view->getTplId()}"]')
						)
					})
				</script>
			</div>
		HTML;
	}

	private function getGridOrder(): array
	{
		return ['SORT' => 'ASC'];
	}

	private function buildDocumentFilter(
		\Bitrix\Main\ORM\Query\Query $query,
		array $document,
		int $index,
		array $filterData,
	): ConditionTree
	{
		$documentType = $document['documentType'];
		$conditionTree = new ConditionTree();
		$conditionTree
			->where('MODULE_ID', $documentType[0])
			->where('ENTITY', $documentType[1])
			->where('DOCUMENT_TYPE', $documentType[2])
			->where('ACTIVE', 'Y')
			->where('IS_SYSTEM', 'N')
			->where('AUTO_EXECUTE', '<', CBPDocumentEventType::Automation)
		;
		$this->applyNameFilter($conditionTree, $filterData);

		$categoryId = $document['categoryId'];
		if ($categoryId !== null && isset($filterData['fields']['SYSTEM_PRESET']))
		{
			$settingsAlias = 'SETTINGS_' . $index;
			$query->registerRuntimeField(new \Bitrix\Main\Entity\ReferenceField(
				$settingsAlias,
				WorkflowTemplateSettingsTable::class,
				\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.TEMPLATE_ID')
					->where('ref.NAME', WorkflowTemplateSettingsTable::SHOW_CATEGORY_PREFIX . $categoryId),
				['join_type' => 'LEFT']
			));

			$conditionTree->whereNot($settingsAlias . '.VALUE', 'N');
		}

		$documentFilter = \Bitrix\Main\ORM\Query\Query::filter()
			->logic('or')
			->where('SECTION.SECTION_ID', $document['templateSection'])
			->where($conditionTree)
		;

		if ($categoryId === null || (($filterData['fields']['SYSTEM_PRESET'] ?? '') !== self::SHOW_IN_STARTER))
		{
			return $documentFilter;
		}

		return \Bitrix\Main\ORM\Query\Query::filter()
			->logic('and')
			->where($documentFilter)
			->where($this->buildCategoryFilter($categoryId))
		;
	}

	private function addToolbar(): void
	{
		$filterParams = [
			'FILTER_ID' => $this->filterId,
			'GRID_ID' => $this->gridId,
			'FILTER' => $this->getFilterFields(),
			'FILTER_PRESETS' => $this->getFilterPresets(),
			'ENABLE_LABEL' => true,
			'ENABLE_LIVE_SEARCH' => true,
			'RESET_TO_DEFAULT_MODE' => true,
			'THEME' => \Bitrix\Main\UI\Filter\Theme::LIGHT,
		];

		$createButton = \Bitrix\UI\Buttons\CreateButton::create([
			'text' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_ADD_TEMPLATE_BUTTON'),
			'color' => \Bitrix\UI\Buttons\Color::SUCCESS,
			'className' => 'ui-btn-no-caps',
			'click' => new \Bitrix\UI\Buttons\JsCode(
			"BX.Bizproc.Component.WorkflowStartList.Instance.editTemplate(event, 0)"
			),
		]);

		$feedbackParams = \Bitrix\Main\Web\Json::encode($this->getFeedbackParams());
		$feedbackButton = \Bitrix\UI\Buttons\CreateButton::create([
			'text' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_FEEDBACK_BUTTON'),
			'size'  => \Bitrix\UI\Buttons\Size::MEDIUM,
			'color' => \Bitrix\UI\Buttons\Color::LIGHT_BORDER,
			'click' => new \Bitrix\UI\Buttons\JsCode(
				"BX.UI.Feedback.Form.open({$feedbackParams});"
			),
		]);

		Toolbar::addFilter($filterParams);
		if ($this->getSingleDocumentContext() !== null)
		{
			Toolbar::addButton($createButton, \Bitrix\UI\Toolbar\ButtonLocation::AFTER_TITLE);
		}
		Toolbar::addButton($feedbackButton, \Bitrix\UI\Toolbar\ButtonLocation::AFTER_FILTER);
		Toolbar::deleteFavoriteStar();
	}

	private function getFeedbackParams(): array
	{
		return [
			'id' => 'bizproc-workflow-start',
			'forms' => [
				[
					'zones' => ['ru', 'by', 'kz'],
					'id' => 786,
					'lang' => 'ru',
					'sec' => 'ys36he',
				],
				[
					'zones' => ['com.br'],
					'id' => 788,
					'lang' => 'br',
					'sec' => 'bdooui',
				],
				[
					'zones' => ['es'],
					'id' => 790,
					'lang' => 'la',
					'sec' => 'ofv5ky',
				],
				[
					'zones' => ['de'],
					'id' => 792,
					'lang' => 'de',
					'sec' => 'sepygg',
				],
				[
					'zones' => ['en'],
					'id' => 794,
					'lang' => 'en',
					'sec' => '32uhqp',
				],
			],
			'presets' => [],
		];
	}

	private function getFilterFields(): array
	{
		$filterFields = [
			'NAME' => [
				'id' => 'NAME',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_GRID_COLUMN_NAME'),
				'type' => 'string',
				'default' => true,
			],
		];

		if ($this->canUseCategoryPreset($this->getDocumentContexts()))
		{
			$filterFields['SYSTEM_PRESET'] = [
				'id' => 'SYSTEM_PRESET',
				'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_FILTER_FIELD_SYSTEM_PRESET') ?? '',
				'type' => 'list',
				'items' => [
					'' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_SYSTEM_PRESET_ITEM') ?? '',
					self::SHOW_IN_STARTER => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_SYSTEM_PRESET_NAME') ?? '',
				],
			];
		}

		return $filterFields;
	}

	private function getFilterPresets(): array
	{
		if ($this->canUseCategoryPreset($this->getDocumentContexts()))
		{
			return [
				self::SHOW_IN_STARTER => [
					'name' => Loc::getMessage('BIZPROC_WORKFLOW_START_LIST_SYSTEM_PRESET_NAME') ?? '',
					'fields' => ['SYSTEM_PRESET' => self::SHOW_IN_STARTER],
					'default' => true,
				],
			];
		}

		return [];
	}

	private function prepareSection(mixed $documentType): string
	{
		return $documentType[0] . '|' . $documentType[2];
	}

	private function buildDocuments(): array
	{
		$documents = $this->arParams['documents'] ?? [];
		if (!empty($documents))
		{
			return $documents;
		}

		$signedDocuments = $this->buildSignedDocuments();
		if (!empty($signedDocuments))
		{
			return $signedDocuments;
		}

		$documentType = CBPHelper::normalizeComplexDocumentId(
			CBPDocument::unSignDocumentType($this->arParams['~signedDocumentType'] ?? '')
		);
		$documentId = CBPHelper::normalizeComplexDocumentId(
			CBPDocument::unSignDocumentType($this->arParams['~signedDocumentId'] ?? '')
		);

		return ($documentType !== null && $documentId !== null)
			? [['documentType' => $documentType, 'documentId' => $documentId]]
			: []
		;
	}

	private function filterDocumentsByRights(array $documents): array
	{
		$allowedDocuments = [];
		foreach ($documents as $document)
		{
			if ($this->canStartWorkflow($document['documentId']))
			{
				$allowedDocuments[] = $document;
			}
		}

		return $allowedDocuments;
	}

	private function buildDocumentContexts(array $documents): array
	{
		$result = [];
		foreach ($documents as $document)
		{
			$result[] = $this->makeDocumentContext($document['documentType'], $document['documentId']);
		}

		return $result;
	}

	private function buildDocumentsByTypeKey(array $documents): array
	{
		$result = [];
		foreach ($documents as $document)
		{
			$result[$document['documentTypeKey']] = $document;
		}

		return $result;
	}

	private function makeDocumentContext(array $documentType, array $documentId): array
	{
		return [
			'documentType' => $documentType,
			'documentId' => $documentId,
			'documentTypeKey' => $this->buildDocumentTypeKey($documentType),
			'templateSection' => $this->prepareSection($documentType),
			'categoryId' => $this->resolveCategoryId($documentId),
			'editorUrl' => $this->resolveEditorUrl($documentType),
			'canEdit' => $this->canCreateWorkflowForDocument($documentId),
			'signedDocumentType' => CBPDocument::signDocumentType($documentType),
			'signedDocumentId' => CBPDocument::signDocumentType($documentId),
		];
	}

	private function getDocumentContexts(): array
	{
		return $this->arResult['documents'] ?? [];
	}

	private function getSingleDocumentContext(): ?array
	{
		$documents = $this->getDocumentContexts();

		return count($documents) === 1 ? $documents[0] : null;
	}

	private function fillDocumentData(): void
	{
		$documents = $this->getDocumentContexts();

		$this->arResult['documentConfigs'] = array_map(
			static fn(array $document): array => [
				'documentTypeKey' => $document['documentTypeKey'],
				'editorUrl' => $document['editorUrl'],
				'canEdit' => $document['canEdit'],
				'signedDocumentType' => $document['signedDocumentType'],
				'signedDocumentId' => $document['signedDocumentId'],
			],
			$documents,
		);
	}

	private function buildDocumentTypeKey(array $documentType): string
	{
		return implode('@', $documentType);
	}

	private function canCreateWorkflowForDocument(array $documentId): bool
	{
		return CBPDocument::canUserOperateDocument(
			CBPCanUserOperateOperation::CreateWorkflow,
			$this->getCurrentUserId(),
			$documentId,
		);
	}

	private function resolveEditorUrl(array $documentType): string
	{
		return CBPRuntime::getRuntime()->getDocumentService()->getBizprocEditorUrl($documentType) ?? '';
	}

	private function buildGridId(array $documents): string
	{
		return 'bizproc_workflow_start_list_' . $this->buildDocumentTypesSuffix($documents);
	}

	private function buildFilterId(array $documents): string
	{
		return 'bizproc_workflow_start_list_filter_' . $this->buildDocumentTypesSuffix($documents);
	}

	private function buildDocumentTypesSuffix(array $documents): string
	{
		$suffix = '';
		foreach ($documents as $document)
		{
			$documentType = $document['documentType'][2];
			$suffix .= ($suffix === '' ? '' : '_') . $documentType;
		}

		return $suffix;
	}

	private function hasSingleCategoryAwareDocumentType(array $documents): bool
	{
		$documentTypeKey = null;

		foreach ($documents as $document)
		{
			if (($document['categoryId'] ?? null) === null)
			{
				return false;
			}

			$currentDocumentTypeKey = $document['documentTypeKey'] ?? $this->buildDocumentTypeKey($document['documentType']);
			if ($documentTypeKey === null)
			{
				$documentTypeKey = $currentDocumentTypeKey;

				continue;
			}

			if ($documentTypeKey !== $currentDocumentTypeKey)
			{
				return false;
			}
		}

		return $documentTypeKey !== null;
	}

	private function canUseCategoryPreset(array $documents): bool
	{
		return count($documents) === 1 && $this->hasSingleCategoryAwareDocumentType($documents);
	}

	private function getFilterData(bool $canUseCategoryPreset): array
	{
		$filterOptions = new \Bitrix\Main\UI\Filter\Options($this->filterId);
		$fields = $filterOptions->getFilter($this->getFilterFields());

		if ($canUseCategoryPreset && empty($fields) && $filterOptions->getCurrentFilterId() === 'default_filter')
		{
			$fields['SYSTEM_PRESET'] = self::SHOW_IN_STARTER;
		}

		return [
			'fields' => $fields,
			'searchString' => $filterOptions->getSearchString(),
		];
	}

	private function applyNameFilter(ConditionTree $conditionTree, array $filterData): void
	{
		if (isset($filterData['fields']['NAME']))
		{
			$conditionTree->whereLike('NAME', '%' . $filterData['fields']['NAME'] . '%');
		}

		if (!empty($filterData['searchString']))
		{
			$conditionTree->whereLike('NAME', '%' . $filterData['searchString'] . '%');
		}
	}

	private function buildCategoryFilter(int $categoryId): ConditionTree
	{
		return \Bitrix\Main\ORM\Query\Query::filter()
			->logic('or')
			->where('SECTION.PATH', $categoryId)
			->whereNull('SECTION.PATH')
			->where('SECTION.PATH', '')
		;
	}

	private function resolveCategoryId(?array $documentId): ?int
	{
		if (!is_array($documentId) || ($documentId[0] ?? null) !== 'crm')
		{
			return null;
		}

		static $cache = [];
		$cacheKey = implode('@', $documentId);
		if (!array_key_exists($cacheKey, $cache))
		{
			$cache[$cacheKey] = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentCategoryId($documentId);
		}

		return $cache[$cacheKey];
	}

	private function resolveTemplateStartData(
		\Bitrix\Bizproc\Workflow\Template\Tpl $template,
		array $documents,
		array $templateSections,
		array $templateTriggerSections,
	): array
	{
		$templateDocumentType = $template->getDocumentComplexType();
		$templateStartData = $this->createTemplateStartData($templateDocumentType);

		foreach ($documents as $document)
		{
			$documentMatch = $this->resolveTemplateDocumentMatch(
				$document,
				$templateDocumentType,
				$templateSections,
				$templateTriggerSections,
			);
			if ($documentMatch === null)
			{
				continue;
			}

			$this->applyDocumentMatchToTemplateStartData(
				$templateStartData,
				$document['documentTypeKey'],
				$documentMatch['triggerType'],
			);
		}

		return $templateStartData;
	}

	private function hasResolvedStartDocument(array $templateStartData): bool
	{
		return $templateStartData['preferredStartDocumentTypeKey'] !== null;
	}

	private function createTemplateStartData(array $templateDocumentType): array
	{
		return [
			'documentTypeKeys' => [],
			'triggerType' => '',
			'preferredStartDocumentTypeKey' => null,
			'templateDocumentTypeKey' => $this->buildDocumentTypeKey($templateDocumentType),
		];
	}

	private function resolveTemplateDocumentMatch(
		array $document,
		array $templateDocumentType,
		array $templateSections,
		array $templateTriggerSections,
	): ?array
	{
		$triggerType = $this->findTriggerTypeByTemplateSection(
			$templateTriggerSections,
			$document['templateSection'],
			$document['categoryId'],
		);
		$hasSectionMatch = $this->hasTemplateSectionMatch($templateSections, $document['templateSection']);
		$hasDocumentTypeMatch = $templateDocumentType === $document['documentType'];

		if ($hasDocumentTypeMatch || $hasSectionMatch || $triggerType !== null)
		{
			return ['triggerType' => $triggerType];
		}

		return null;
	}

	private function applyDocumentMatchToTemplateStartData(
		array &$templateStartData,
		string $documentTypeKey,
		?string $triggerType,
	): void
	{
		$templateStartData['documentTypeKeys'][] = $documentTypeKey;
		if ($triggerType !== null && $templateStartData['triggerType'] === '')
		{
			$templateStartData['triggerType'] = $triggerType;
			$templateStartData['preferredStartDocumentTypeKey'] = $documentTypeKey;

			return;
		}

		if ($templateStartData['preferredStartDocumentTypeKey'] === null)
		{
			$templateStartData['preferredStartDocumentTypeKey'] = $documentTypeKey;
		}
	}

	private function hasResolvedEditDocument(array $templateStartData, array $documentsByTypeKey): bool
	{
		if (isset($documentsByTypeKey[$templateStartData['templateDocumentTypeKey']]))
		{
			return true;
		}

		$matchedDocuments = [];
		foreach ($templateStartData['documentTypeKeys'] as $documentTypeKey)
		{
			if (isset($documentsByTypeKey[$documentTypeKey]))
			{
				$matchedDocuments[] = $documentsByTypeKey[$documentTypeKey];
			}
		}

		if (count($matchedDocuments) === 1)
		{
			return true;
		}

		if (count($matchedDocuments) < 2)
		{
			return false;
		}

		$editorUrl = null;
		foreach ($matchedDocuments as $document)
		{
			if (empty($document['editorUrl']))
			{
				return false;
			}

			if ($editorUrl === null)
			{
				$editorUrl = $document['editorUrl'];

				continue;
			}

			if ($editorUrl !== $document['editorUrl'])
			{
				return false;
			}
		}

		return $editorUrl !== null;
	}

	private function findTriggerTypeByTemplateSection(
		array $templateTriggerSections,
		string $templateSection,
		int|string|null $categoryId = null,
	): ?string
	{
		foreach ($templateTriggerSections as $templateTriggerSection)
		{
			if (
				($templateTriggerSection['SECTION_ID'] ?? null) === $templateSection
				&& ($categoryId === null || (string)$categoryId === (string)($templateTriggerSection['SECTION_PATH'] ?? ''))
			)
			{
				return $templateTriggerSection['TRIGGER_TYPE'] ?? null;
			}
		}

		return null;
	}

	private function hasTemplateSectionMatch(array $templateSections, string $templateSection): bool
	{
		foreach ($templateSections as $section)
		{
			if (($section['SECTION_ID'] ?? null) === $templateSection)
			{
				return true;
			}
		}

		return false;
	}

	private function getTemplateSectionsMap(array $templateIds): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		$result = [];

		$sectionsResult = WorkflowTemplateSectionTable::query()
			->setSelect(['TEMPLATE_ID', 'SECTION_ID'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->exec()
		;
		while ($section = $sectionsResult->fetch())
		{
			$templateId = (int)($section['TEMPLATE_ID'] ?? 0);
			if ($templateId > 0)
			{
				$result[$templateId][] = $section;
			}
		}

		return $result;
	}

	private function getTemplateTriggerSectionsMap(array $templateIds): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		$result = [];

		$templateRowsResult = WorkflowTemplateTable::query()
			->setSelect(['ID', 'TEMPLATE'])
			->whereIn('ID', $templateIds)
			->exec()
		;
		while ($templateRow = $templateRowsResult->fetch())
		{
			$templateId = (int)($templateRow['ID'] ?? 0);
			if ($templateId > 0)
			{
				$template = $templateRow['TEMPLATE'][0] ?? null;
				$templateChildren = is_array($template) ? ($template['Children'] ?? []) : [];
				$triggers = WorkflowTemplateTriggerTable::filterTriggersByActivities($templateChildren);
				foreach ($triggers as $trigger)
				{
					$section = ($trigger['CONFIGURATION'] ?? null)?->getSection();
					if ($section === null)
					{
						continue;
					}

					$result[$templateId][] = [
						'TRIGGER_TYPE' => $trigger['TRIGGER_TYPE'] ?? null,
						'SECTION_ID' => $section->id,
						'SECTION_PATH' => $section->path,
					];
				}
			}
		}

		return $result;
	}
}

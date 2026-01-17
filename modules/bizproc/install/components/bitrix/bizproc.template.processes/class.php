<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\GridTemplateRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\GridTemplateResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Internal\Grid\WorkflowTemplates\WorkflowTemplateGridHelper;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Grid;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Search\Content;
use Bitrix\Main\UI\Filter;
use Bitrix\Main\UI\Filter\Theme;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\UI\Buttons\AddButton;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Icon;
use Bitrix\UI\Buttons\LinkTarget;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BizprocTemplateProcesses extends CBitrixComponent implements Controllerable, Errorable
{
	protected const GRID_ID = 'bizproc_template_processes';
	protected const NAVIGATION_ID = 'page';
	protected const FILTER_ID = self::GRID_ID . '_filter';

	protected Filter\Options $filterOptions;
	protected Grid\Options $gridOptions;
	private ErrorCollection $errorCollection;

	public function __construct($component = null)
	{
		parent::__construct($component);

		$this->errorCollection = new ErrorCollection();
		$this->filterOptions = new Filter\Options(self::FILTER_ID);
		$this->gridOptions = new Grid\Options(self::GRID_ID);
	}

	public function configureActions(): array
	{
		return [];
	}

	public function deleteTemplateAction(int $id): void
	{
		$this->checkModules();

		if ($this->hasErrors())
		{
			return;
		}

		$user = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if (!$user->isAdmin())
		{
			$this->setError(new Error(Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_DELETE_UNAUTHORIZED')));

			return;
		}

		$workflowsRequest = new GridTemplateRequest();
		$workflowsRequest->setLimit(1);
		$workflowsRequest->setFilterUserId($this->getCurrentUserId());
		$workflowsRequest->setFilter(['=ID' => $id]);

		$response = (new WorkflowTemplateService())->getList($workflowsRequest);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return;
		}

		if ($response->getTotalCount() <= 0)
		{
			$this->setError(new Error(Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_DELETE_ERROR')));

			return;
		}

		try
		{
			$currentUser = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

			$templateDeleteService = new TemplateDeleteService();
			$templateDeleteService->deleteTemplates(
				[$id],
				initiator: $currentUser,
			);
			$templateDeleteService->killWorkflow([$id]);
		}
		catch (Throwable $exception)
		{
			$this->setError(new Error(Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_DELETE_ERROR')));
		}
	}

	public function executeComponent(): void
	{
		global $APPLICATION;
		$APPLICATION->SetTitle(Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_TITLE'));
		$this->init();

		if (!$this->hasErrors())
		{
			$this->fillGridInfo();
			$this->addToolbar();
			$this->fillGridData();
			$this->includeComponentTemplate();

			return;
		}

		$this->includeComponentTemplate('error');
	}

	private function init(): void
	{
		$this->checkModules();
		$this->checkAdmin();

		$this->arResult['viewData'] = [];
	}

	private function checkAdmin(): void
	{
		$user = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if ($user->isAdmin())
		{
			return;
		}

		$this->setError(new Error(Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_UNAUTHORIZED')));
	}

	private function checkModules(): void
	{
		if (Loader::includeModule('bizproc'))
		{
			return;
		}

		$errorMessage = Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_MODULE_ERROR', ['#MODULE#' => 'bizproc']);
		$this->setError(new Error($errorMessage));
	}

	private function setError(Error $error): void
	{
		$this->errorCollection->setError($error);
	}

	public function hasErrors(): bool
	{
		return !$this->errorCollection->isEmpty();
	}

	private function fillGridInfo(): void
	{
		$this->arResult['gridId'] = static::GRID_ID;
		$this->arResult['filterId'] = static::FILTER_ID;
		$this->arResult['navigationId'] = static::NAVIGATION_ID;
		$this->arResult['gridColumns'] = $this->getGridColumns();
		$this->arResult['pageNavigation'] = $this->getPageNavigation();
		$this->arResult['pageSizes'] = $this->getPageSizes();
	}

	private function getSortColumns(): array
	{
		return [
			'name' => 'NAME',
			'updated_user' => 'UPDATED_USER.NAME',
			'created_user' => 'CREATED_USER.NAME',
			'modified' => 'MODIFIED',
		];
	}

	private function getGridColumns(): array
	{
		return [
			[
				'id' => 'NAME',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_NAME'),
				'default' => true,
				'sort' => 'name',
				'width' => 400,
			],
			[
				'id' => 'ACTIONS',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_ACTION'),
				'default' => true,
				'sort' => '',
			],
			[
				'id' => 'EDITOR',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_UPDATED_BY'),
				'default' => true,
				'sort' => 'updated_user',
			],
			[
				'id' => 'CREATOR',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_CREATED_BY'),
				'sort' => 'created_user',
			],
			[
				'id' => 'MODIFIED',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_MODIFIED'),
				'default' => true,
				'sort' => 'modified',
			],
		];
	}

	private function getPageNavigation(): PageNavigation
	{
		$navParams = $this->gridOptions->GetNavParams();
		$pageNavigation = new PageNavigation(static::NAVIGATION_ID);
		$pageNavigation->setPageSize($navParams['nPageSize'])->initFromUri();
		$currentPage = $this->request->getQuery(static::NAVIGATION_ID);

		if (is_numeric($currentPage))
		{
			$pageNavigation->setCurrentPage((int)$currentPage);
		}

		return $pageNavigation;
	}

	private function getPageSizes(): array
	{
		return [
			['NAME' => '5', 'VALUE' => '5'],
			['NAME' => '10', 'VALUE' => '10'],
			['NAME' => '20', 'VALUE' => '20'],
			['NAME' => '50', 'VALUE' => '50'],
			['NAME' => '100', 'VALUE' => '100'],
		];
	}

	private function addToolbar(): void
	{
		Toolbar::addFilter([
			'FILTER_ID' => static::FILTER_ID,
			'GRID_ID' => static::GRID_ID,
			'FILTER' => $this->getFilterFields(),
			'FILTER_PRESETS' => $this->getFilterPresets(),
			'ENABLE_LABEL' => true,
			'RESET_TO_DEFAULT_MODE' => true,
			'THEME' => Theme::MUTED,
		]);

		Toolbar::addButton(
			new AddButton([
				'id' => 'add_new_workflow_button',
				'color' => Color::SUCCESS,
				'text' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_ADD_BUTTON'),
				'dataset' => [
					'toolbar-collapsed-icon' => Icon::ADD,
				],
				'link' => '/bizprocdesigner/editor',
				'target' => LinkTarget::LINK_TARGET_BLANK,
			]),
			ButtonLocation::AFTER_TITLE,
		);

		Toolbar::addFavoriteStar();
	}

	private function getFilterFields(): array
	{
		return [
			'NAME' => [
				'id' => 'NAME',
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_NAME'),
				'type' => 'string',
				'default' => true,
			],
		];
	}

	private function getFilterPresets(): array
	{
		return [
			'filter_default' => [
				'name' => Loc::getMessage('BIZPROC_TEMPLATE_PROCESSES_COLUMN_NAME'),
				'fields' => [
					'NAME' => '',
				],
				'default' => false,
			],
		];
	}

	private function fillGridData(): void
	{
		/** @var PageNavigation $pageNav */
		$pageNav = $this->arResult['pageNavigation'];
		$response = $this->fetchWorkflows($pageNav->getLimit(), $pageNav->getOffset());

		if ($response->isSuccess())
		{
			$pageNav->setRecordCount($response->getTotalCount());
		}
		else
		{
			$this->addErrors($response->getErrors());
		}

		$workflowTemplateGridHelper = new WorkflowTemplateGridHelper();
		$this->arResult['viewData']['userId'] = $this->getCurrentUserId();
		$this->arResult['viewData']['gridData'] = $workflowTemplateGridHelper->createGridData($response->getCollection());
	}

	private function fetchWorkflows(int $limit, int $offset): GridTemplateResponse
	{
		$workflowStateService = new WorkflowTemplateService();
		$workflowsRequest = (new GridTemplateRequest())
			->setLimit($limit)
			->setOffset($offset)
		;
		$this->setFiltersToRequest($workflowsRequest);
		$this->setSortingToRequest($workflowsRequest);
		$workflowsRequest->setFilterUserId($this->getCurrentUserId());

		return $workflowStateService->getList($workflowsRequest);
	}

	private function setFiltersToRequest(GridTemplateRequest $templateToGet): void
	{
		$templateToGet->setFilterUserId($this->getCurrentUserId());
		$currentFilters = $this->filterOptions->getFilter($this->getFilterFields());
		$filter = [];

		if (!empty($currentFilters['NAME']))
		{
			$filter['%NAME'] = Content::prepareStringToken(htmlspecialcharsbx($currentFilters['NAME']));
		}

		$findValue = $currentFilters['FIND'] ?? null;

		if (!empty($findValue) && Content::canUseFulltextSearch($findValue = trim($findValue)))
		{
			$templateToGet->setFilterSearchQuery(Content::prepareStringToken(htmlspecialcharsbx($findValue)));
		}

		$templateToGet->setFilter($filter);
	}

	private function getCurrentUserId(): int
	{
		return CurrentUser::get()->getId();
	}

	private function setSortingToRequest(GridTemplateRequest $templateToGet): void
	{
		$defaultSorting = ['MODIFIED' => 'DESC'];

		/** @var array<string,array<string,string>> $sortingPayload */
		$sortingPayload = $this->gridOptions->getSorting(['sort' => $defaultSorting]);
		$key = array_key_first($sortingPayload['sort']);
		$sortColumns = $this->getSortColumns();

		if (array_key_exists($key, $sortColumns))
		{
			$column = $sortColumns[$key];
			$direction = $sortingPayload['sort'][$key];
			$templateToGet->setOrder([$column => $direction]);

			return;
		}

		$templateToGet->setOrder($defaultSorting);
	}

	public function addErrors(array $errors): static
	{
		$this->errorCollection->add($errors);

		return $this;
	}

	public function getErrors(): array
	{
		return $this->errorCollection->toArray();
	}

	public function getErrorByCode($code): Error|null
	{
		return $this->errorCollection->getErrorByCode($code);
	}
}

<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

use Bitrix\Main\Web\Uri;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGrid;
use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;

Loc::loadMessages(__FILE__);

class BizprocAiAgentsComponent extends \Bitrix\Bizproc\Automation\Component\Base
{
	protected const BIZPROC_AI_AGENTS_TOTAL_COUNTER_ID = 'bizproc-ai-agents-total-counter';
	private const GRID_ACTION_MORE = 'more';
	private const GRID_ACTION_PAGINATION = 'pagination';

	private ?AiAgentsGrid $grid = null;
	private AiAgentsGridHelper $gridHelper;

	public function __construct($component = null, AiAgentsGridHelper $gridHelper = new AiAgentsGridHelper())
	{
		parent::__construct($component);
		$this->gridHelper = $gridHelper;
	}

	private function loadModules(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		if (!Loader::includeModule('bizprocdesigner'))
		{
			return false;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		return true;
	}

	protected function prepareData(): array
	{
		$result = [];

		$gridId = $this->gridHelper->getGridId();
		$result['GRID_ID'] = $gridId;
		$result['FILTER_ID'] = $gridId;

		$grid = $this->getGrid();
		$grid->processRequest();

		$currentPage = $this->getCurrentPage();
		$grid->getPagination()?->setCurrentPage($currentPage);

		$grid->setRawRowsWithLazyLoadPagination(function (array $ormParams)
		{
			return $this->gridHelper->getGridDataWithOrmParams($ormParams);
		});

		$result['GET_TOTAL_COUNTER_ID'] = self::BIZPROC_AI_AGENTS_TOTAL_COUNTER_ID;

		$result['GRID_PARAMS'] = \Bitrix\Main\Grid\Component\ComponentParams::get(
			$grid,
			$this->gridHelper->buildGridParams($grid, $currentPage)
		);

		$result['GRID_FILTER'] = $grid->getFilter();
		$result['FILTER_PRESETS'] = $grid->getFilter()?->getFilterPresets();

		// TODO: REPLACE WITH LOGIC
		$result['AVAILABLE_AI_AGENTS_COUNT'] = 0;
		$result['MENU_ITEMS'] = $this->getMenuItems();

		$result['SHOW_FACADE_FILTER'] = false;
		$result['SHOW_AVAILABLE_AGENTS_COUNT'] = false;

		$result['BASE_BIZPROC_DESIGNER_URI'] = $this->getBaseBizprocDesignerUri();
		$result['AI_AGENTS_HEADER_ADD_BUTTON_UNIQUE_ID'] = 'BIZPROC_AI_AGENTS_HEADER_ADD_BUTTON';

		return $result;
	}

	private function getGrid(): AiAgentsGrid
	{
		if (!isset($this->grid))
		{
			$this->grid = $this->gridHelper->createGrid($this->arParams);
		}

		return $this->grid;
	}

	public function executeComponent(): void
	{
		if ($this->loadModules())
		{
			(new \Bitrix\Bizproc\Public\Service\Template\NodesInstallerService())
				->trySyncSection('AI_AGENT')
			;

			$this->arResult = $this->prepareData();
		}

		$this->includeComponentTemplate();
	}

	private function getCurrentPage(): int
	{
		if (!$this->request->isAjaxRequest())
		{
			return 1;
		}

		$action = (string)$this->request->get('grid_action', '');

		return match ($action)
		{
			self::GRID_ACTION_MORE => $this->normalizePage($this->request->get($this->gridHelper->getNavParamName())),
			self::GRID_ACTION_PAGINATION => $this->grid->getPagination()?->getCurrentPage() ?? 1,
			default => 1,
		};
	}

	/**
	 * Normalize and validate the page number input.
	 *
	 * @param mixed $value
	 * @return int
	 */
	private function normalizePage(mixed $value): int
	{
		$page = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'default' => 1
				]
			]
		);

		return (int)$page;
	}

	private function getMenuItems(): array
	{
		$items = [];

		$items[] = [
			'TEXT' => Loc::getMessage('BIZPROC_AI_AGENTS_MENU_MY_AGENTS'),
			'URL' => '/bizproc/ai/agents/',
			'ID' => 'bizproc_ai_agents',
		];

		return $items;
	}

	private function getBaseBizprocDesignerUri(): Uri
	{
		return (new AiAgentsGridHelper)
			->getBaseBizprocDesignerUri()
			->withQuery('START_TRIGGER=');
	}
}

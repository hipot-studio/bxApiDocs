<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Grid\FilterPresetSynchronizer;
use Bitrix\BIConnector\Internal\Grid\RowRule\Row\RowRuleRawRowMapper;
use Bitrix\BIConnector\Internal\Grid\RowRule\RowRuleGrid;
use Bitrix\BIConnector\Internal\Grid\RowRule\Settings\RowRuleSettings;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleFieldProvider;
use Bitrix\BIConnector\Public\Provider\RowRule\RowRuleProvider;
use Bitrix\Biconnector\Internal\Grid\GridStubRenderer;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Filter\Component\ComponentParams;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BIConnectorRowRuleListComponent extends CBitrixComponent
{
	private const STUB_IMAGE_PATH = '/bitrix/images/biconnector/workspace-analytic-grid/empty-state-icon.svg';
	private const CREATE_ONCLICK = 'BX.BIConnector.RowRulesGrid.Instance.create()';
	private const RELOAD_ONCLICK = 'BX.BIConnector.RowRulesGrid.Instance.reload()';
	// The filter stands outside the grid: a reload of the grid would leave it without the tables.
	private const RELOAD_PAGE_ONCLICK = 'window.location.reload()';

	private RowRuleGrid $grid;
	private ?RowRuleProvider $provider = null;
	private int $userId;
	private bool $isTablesLoadFailed = false;

	public function executeComponent(): void
	{
		if (!Loader::includeModule('biconnector'))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_ERROR_INCLUDE_MODULE');
			$this->includeComponentTemplate();

			return;
		}

		$this->userId = (int)CurrentUser::get()->getId();
		if (!$this->canManage())
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_ERROR_ACCESS');
			$this->includeComponentTemplate();

			return;
		}

		$presets = RowRuleGrid::getFilterPresets($this->userId, (string)CurrentUser::get()->getFormattedName());
		FilterPresetSynchronizer::sync(RowRuleGrid::GRID_ID, $presets);

		$this->initGrid();
		$this->loadRows();
		$this->initFilterBar($presets);
		$this->initCreateButton();

		$this->arResult['GRID'] = $this->grid;
		$this->includeComponentTemplate();
	}

	/**
	 * The same check as the rule actions run: the component may be included apart from the controller.
	 */
	private function canManage(): bool
	{
		return Feature::isRowRulesEnabled()
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_ROW_RULES)
		;
	}

	private function initGrid(): void
	{
		$settings = new RowRuleSettings([
			'ID' => RowRuleGrid::GRID_ID,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'SHOW_TOTAL_COUNTER' => true,
			'SHOW_ROW_ACTIONS_MENU' => true,
			'EDITABLE' => false,
		]);
		$tables = $this->getTables();
		$this->isTablesLoadFailed = $tables === null;
		$settings->setTables($tables ?? []);

		$this->grid = new RowRuleGrid($settings);
	}

	/**
	 * @return array<string, string>|null Table code => name; null when the tables are not read.
	 */
	private function getTables(): ?array
	{
		try
		{
			$items = $this->readTableItems();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return null;
		}

		$tables = [];
		foreach ($items as $table)
		{
			$code = (string)($table['code'] ?? '');
			if ($code !== '')
			{
				$tables[$code] = (string)($table['name'] ?? $code);
			}
		}

		return $tables;
	}

	/**
	 * Reading of the tables and the rules is kept apart so that a test can put its own answers in place of them.
	 */
	protected function readTableItems(): array
	{
		return (new RowRuleFieldProvider())->getTables()['items'] ?? [];
	}

	protected function readRules(array $request): array
	{
		$this->provider ??= new RowRuleProvider();

		return $this->provider->getList($request, $this->userId);
	}

	private function loadRows(): void
	{
		// Without the tables the filter cannot narrow the list: the page is offered to be loaded again.
		if ($this->isTablesLoadFailed)
		{
			$this->grid->setRawRows([]);
			$this->arResult['GRID_STUB'] = $this->getLoadFailedStub(self::RELOAD_PAGE_ONCLICK);

			return;
		}

		try
		{
			// The page switch is an action of the pagination: it needs the total before the request is processed.
			$countRequest = array_merge($this->grid->getListRequest(), ['offset' => 0, 'limit' => 1]);
			$total = (int)$this->readRules($countRequest)['total'];

			$this->grid->initPagination($total);
			$this->grid->processRequest();
			$this->grid->clampCurrentPage();

			$items = $total > 0
				? $this->readRules($this->grid->getListRequest())['items']
				: []
			;
		}
		catch (\Throwable $exception)
		{
			// The text of a database failure is not for the page: it goes to the log only.
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			$this->grid->setRawRows([]);
			$this->arResult['GRID_STUB'] = $this->getLoadFailedStub(self::RELOAD_ONCLICK);

			return;
		}

		$this->grid->setRawRows(RowRuleRawRowMapper::map($items));

		$this->arResult['GRID_STUB'] = $total === 0 && !$this->grid->hasUserFilter() ? $this->getStub() : null;
	}

	private function initFilterBar(array $presets): void
	{
		$filter = $this->grid->getFilter();
		$options = $filter
			? ComponentParams::get(
				$filter,
				[
					'GRID_ID' => $this->grid->getId(),
					'FILTER_PRESETS' => $presets,
					'DISABLE_SEARCH' => true,
				],
			)
			: ['FILTER_ID' => $this->grid->getId()]
		;

		Toolbar::addFilter($options);
	}

	private function initCreateButton(): void
	{
		$button = new Buttons\CreateButton([
			'dataset' => [
				'toolbar-collapsed-icon' => Buttons\Icon::ADD,
			],
		]);
		$button->getAttributeCollection()['onclick'] = self::CREATE_ONCLICK;

		Toolbar::addButton($button, ButtonLocation::AFTER_TITLE);
	}

	private function getStub(): string
	{
		$button = new Buttons\CreateButton([
			'text' => Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_STUB_BUTTON'),
			'color' => Buttons\Color::PRIMARY,
			'icon' => Buttons\Icon::ADD,
			'air' => true,
		]);
		$button->getAttributeCollection()['onclick'] = self::CREATE_ONCLICK;

		return GridStubRenderer::render(
			self::STUB_IMAGE_PATH,
			(string)Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_STUB_TITLE'),
			null,
			$button,
		);
	}

	private function getLoadFailedStub(string $onclick): string
	{
		$button = new Buttons\Button([
			'text' => Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_LOAD_FAILED_BUTTON'),
			'color' => Buttons\Color::LIGHT_BORDER,
			'air' => true,
		]);
		$button->getAttributeCollection()['onclick'] = $onclick;

		return GridStubRenderer::render(
			self::STUB_IMAGE_PATH,
			(string)Loc::getMessage('BICONNECTOR_ROW_RULE_LIST_LOAD_FAILED_TITLE'),
			null,
			$button,
		);
	}
}

<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\PasswordlessRequestsGrid;
use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings\PasswordlessRequestsSettings;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\PasswordlessRequestsGridHelper;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options;

class CMailPasswordlessRequestsListComponent extends CBitrixComponent
{
	protected string $filterId = 'MAIL_PASSWORDLESS_REQUESTS';
	protected const DEFAULT_PAGE_SIZE = 20;
	private ?PasswordlessRequestsGrid $grid = null;
	private PasswordlessRequestsGridHelper $gridHelper;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->gridHelper = new PasswordlessRequestsGridHelper();
	}

	public function executeComponent(): void
	{
		$canManage = Feature::isPasswordlessConnectAvailable() && MailAccess::hasCurrentUserAccessToMassConnect();

		if (!$canManage)
		{
			showError('access denied');

			return;
		}

		$this->arResult = $this->prepareData();
		$this->includeComponentTemplate();
	}

	protected function prepareData(): array
	{
		$result = [];
		$result['GRID_ID'] = $this->filterId;
		$result['FILTER_ID'] = $this->filterId;
		$result['TITLE'] = Loc::getMessage('MAIL_PASSWORDLESS_REQUESTS_LIST_TITLE');

		$grid = $this->getGrid();
		$grid->processRequest();

		$grid->setRawRowsWithLazyLoadPagination(function (array $ormParams) {
			$filterOptions = new Options($this->filterId);
			$filterData = $filterOptions->getFilter();

			return $this->gridHelper->getGridData(
				$ormParams['limit'] ?? self::DEFAULT_PAGE_SIZE,
				$ormParams['offset'] ?? 0,
				$filterData,
			);
		});

		$result['GRID_PARAMS'] = ComponentParams::get(
			$grid,
		);

		$result['GRID_FILTER'] = $grid->getFilter();
		$result['FILTER_PRESETS'] = $grid->getFilter()?->getFilterPresets();

		$result['GRID_PARAMS']['ALLOW_SORT'] = false;
		$result['GRID_PARAMS']['SHOW_PAGINATION'] = true;
		$result['GRID_PARAMS']['SHOW_TOTAL_COUNTER'] = false;
		$result['GRID_PARAMS']['SHOW_PAGESIZE'] = true;

		return $result;
	}

	private function getGrid(): PasswordlessRequestsGrid
	{
		if ($this->grid === null)
		{
			$settings = new PasswordlessRequestsSettings([
				'ID' => $this->filterId,
			]);

			$this->grid = new PasswordlessRequestsGrid($settings);
			$this->grid->setTotalCountCalculator(function () {
				$filterOptions = new Options($this->filterId);
				$filterData = $filterOptions->getFilter();

				return $this->gridHelper->getTotalCount($filterData);
			});
		}

		return $this->grid;
	}
}

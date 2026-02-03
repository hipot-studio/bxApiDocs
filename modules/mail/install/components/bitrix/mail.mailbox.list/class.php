<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Mail\Grid\MailboxSettingsGrid\MailboxGrid;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Settings\MailboxSettings;
use Bitrix\Mail\Helper\Config\Guide;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\MailboxSettingsGridHelper;
use Bitrix\Main\Localization\Loc;

class CMailMailboxListComponent extends CBitrixComponent
{
	protected string $filterId = 'MAIL_EMPLOYEE_MAILBOX_LIST';
	protected const DEFAULT_PAGE_SIZE = 20;
	private ?MailboxGrid $grid = null;
	private MailboxSettingsGridHelper $mailboxHelper;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->mailboxHelper = new MailboxSettingsGridHelper();
	}

	public function executeComponent(): void
	{
		$canManage = MailAccess::hasCurrentUserAccessToMailboxGrid();

		if (!$canManage)
		{
			showError('access denied');

			return;
		}

		$this->arResult = $this->prepareData();
		$this->arResult['ACCESS_RIGHTS_ENABLED'] = LicenseManager::isAccessRightsEnabled();
		$this->arResult['MAILBOX_MASS_CONNECT_ENABLED'] = LicenseManager::isMailboxesMassConnectEnabled();
		$this->arResult['NEED_SHOW_MAILBOX_LIST_HINT'] = $this->needShowMailboxListHint();
		$this->arResult['MAILBOX_LIST_HINT_NAME'] = Guide::getMailboxListHintOptionName();

		$this->includeComponentTemplate();
	}

	protected function prepareData(): array
	{
		$result = [];
		$result['GRID_ID'] = $this->filterId;
		$result['FILTER_ID'] = $this->filterId;
		$result['TITLE'] = Loc::getMessage('MAIL_MAILBOX_LIST_TITLE');

		$result = array_merge($result, $this->getAccess());

		$grid = $this->getGrid();
		$grid->processRequest();

		$grid->setRawRowsWithLazyLoadPagination(function (array $ormParams) {
			$filterOptions = new \Bitrix\Main\UI\Filter\Options($this->filterId);
			$filterData = $filterOptions->getFilter();

			return $this->mailboxHelper->getGridDataWithOrmParams($ormParams, $filterData);
		});

		$result['GRID_PARAMS'] = \Bitrix\Main\Grid\Component\ComponentParams::get(
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

	private function getGrid(): MailboxGrid
	{
		if ($this->grid === null)
		{
			$settings = new MailboxSettings([
				'ID' => $this->filterId,
			]);

			$this->grid = new MailboxGrid($settings);
			$this->grid->setTotalCountCalculator(function () {
				return $this->mailboxHelper->getTotalCount();
			});
		}

		return $this->grid;
	}

	private function getAccess(): array
	{
		$accessValues = [];

		$accessValues['HAS_ACCESS_TO_MASS_CONNECT'] = MailAccess::hasCurrentUserAccessToMassConnect();
		$accessValues['HAS_ACCESS_TO_EDIT_PERMISSIONS'] = MailAccess::hasCurrentUserAccessToPermission();

		return $accessValues;
	}

	private function needShowMailboxListHint(): bool
	{
		return !Guide::wasMailboxListShown();
	}
}

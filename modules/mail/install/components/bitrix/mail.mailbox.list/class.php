<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Mail\Grid\MailboxSettingsGrid\MailboxGrid;
use Bitrix\Mail\Grid\MailboxSettingsGrid\Settings\MailboxSettings;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Config\Guide;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\Mailbox\PasswordlessConnectHelper;
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
			$this->includeComponentTemplate('access_denied');

			return;
		}

		$this->arResult = $this->prepareData();
		$this->arResult['ACCESS_RIGHTS_ENABLED'] = LicenseManager::isAccessRightsEnabled();
		$this->arResult['MAILBOX_MASS_CONNECT_ENABLED'] = LicenseManager::isMailboxesMassConnectEnabled();
		$this->arResult['IS_PASSWORDLESS_CONNECT_AVAILABLE'] = Feature::isPasswordlessConnectAvailable();
		$this->arResult['MAILBOX_LIST_HINT_NAME'] = Guide::getMailboxListHintOptionName();
		$this->arResult['PASSWORDLESS_SENT_TOTAL_COUNT'] = $this->getPasswordlessSentTotalCount();
		$this->arResult['NEED_HIGHLIGHT_GEAR_BUTTON'] = Feature::isPasswordlessConnectAvailable()
			&& !Guide::wasMailboxListGearHighlightShown();
		$this->arResult['HIGHLIGHT_GEAR_BUTTON_OPTION_NAME'] = Guide::getMailboxListGearHighlightOptionName();

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

		$grid->setRawRowsWithLazyLoadPagination(
			fn(array $ormParams) => $this->getGridDataForCurrentFilter($ormParams),
		);

		$result['GRID_PARAMS'] = \Bitrix\Main\Grid\Component\ComponentParams::get(
			$grid,
		);

		$result['GRID_FILTER'] = $grid->getFilter();
		$result['FILTER_PRESETS'] = $grid->getFilter()?->getFilterPresets();

		$result['GRID_PARAMS']['ALLOW_SORT'] = false;
		$result['GRID_PARAMS']['SHOW_PAGINATION'] = true;
		$result['GRID_PARAMS']['SHOW_TOTAL_COUNTER'] = false;
		$result['GRID_PARAMS']['SHOW_PAGESIZE'] = true;

		$result['GRID_PARAMS']['SHOW_ACTION_PANEL'] = false;

		$result['BULK_ACTIONS_AVAILABLE'] = Feature::isMailboxGridBulkActionsAvailable();
		if ($result['BULK_ACTIONS_AVAILABLE'])
		{
			$result['GRID_PARAMS']['TOP_ACTION_PANEL_RENDER_TO'] = '.mail-mailbox-list-actionpanel-container';
			$result['GRID_PARAMS']['TOP_ACTION_PANEL_PINNED_MODE'] = true;
			$result['GRID_PARAMS']['TOP_ACTION_PANEL_CLASS'] = 'mail-mailbox-list-action-panel';
			$result['GRID_PARAMS']['ACTION_PANEL_OPTIONS'] = ['MAX_HEIGHT' => 56];
		}

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
			$this->grid->setTotalCountCalculator(
				fn() => $this->getGridTotalCountForCurrentFilter(),
			);
		}

		return $this->grid;
	}

	protected function getGridDataForCurrentFilter(array $ormParams): array
	{
		return $this->getMailboxHelper()->getGridDataWithOrmParams(
			$ormParams,
			$this->getCurrentFilterData(),
		);
	}

	protected function getGridTotalCountForCurrentFilter(): int
	{
		return $this->getMailboxHelper()->getTotalCount($this->getCurrentFilterData());
	}

	protected function getCurrentFilterData(): array
	{
		return (new \Bitrix\Main\UI\Filter\Options($this->filterId))->getFilter();
	}

	protected function getMailboxHelper(): MailboxSettingsGridHelper
	{
		return $this->mailboxHelper;
	}

	private function getAccess(): array
	{
		$accessValues = [];

		$accessValues['HAS_ACCESS_TO_MASS_CONNECT'] = MailAccess::hasCurrentUserAccessToMassConnect();
		$accessValues['HAS_ACCESS_TO_EDIT_PERMISSIONS'] = MailAccess::hasCurrentUserAccessToPermission();

		return $accessValues;
	}

	private function getPasswordlessSentTotalCount(): int
	{
		if (
			!Feature::isPasswordlessConnectAvailable()
			|| !MailAccess::hasCurrentUserAccessToMassConnect()
		)
		{
			return 0;
		}

		return PasswordlessConnectHelper::getSentTotalCount();
	}
}

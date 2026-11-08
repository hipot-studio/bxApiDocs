<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Internal\Services\DashboardSettings\DashboardSettingsModeResolver;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;


final class OpenSettingsAction extends FormatMenuAction
{
	private ?DashboardSettingsModeResolver $modeResolver;

	public function __construct(?DashboardSettingsModeResolver $modeResolver = null)
	{
		$this->modeResolver = $modeResolver;
	}

	public static function getId(): ?string
	{
		return 'settings';
	}

	public function processRequest(Main\HttpRequest $request): ?Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_OPEN_SETTINGS') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => (int)$rawFields['ID'],
			'TYPE' => $rawFields['TYPE'],
			'STATUS' => $rawFields['STATUS'],
		]);

		if (!$this->getModeResolver()->canOpen($accessItem))
		{
			return null;
		}

		$dashboardId = (int)$rawFields['ID'];
		$dashboardType = (string)($rawFields['TYPE']);
		$type = mb_strtolower($dashboardType);
		$this->onclick = "
			BX.BIConnector.ApacheSupersetAnalytics.sendAnalytics(
				'edit',
				'editing_card_report',
				{type: '{$type}', c_element: 'context_menu', status: 'success'}
			);
			BX.BIConnector.DashboardManager.openSettingsSlider({$dashboardId}, '{$dashboardType}')
		";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-settings');

		return $result;
	}

	private function getModeResolver(): DashboardSettingsModeResolver
	{
		$this->modeResolver ??= new DashboardSettingsModeResolver();

		return $this->modeResolver;
	}
}

<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Action;

use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Only opens the dashboard settings: the public links setting is changed there, not in the registry.
 * Shown without the view right: a controller reaches the settings of a dashboard by its public links.
 */
final class OpenSettingsAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'settings';
	}

	public function getControl(array $rawFields): ?array
	{
		$dashboardId = (int)($rawFields['DASHBOARD_ID'] ?? 0);
		$dashboardType = (string)($rawFields['DASHBOARD_TYPE'] ?? '');
		if ($dashboardId <= 0 || $dashboardType === '')
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.ShareRegistry.GridManager.Instance.openSettings({$dashboardId}, '"
			. \CUtil::JSEscape($dashboardType)
			. "')"
		;

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--o-settings');

		return $result;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_ACTION_SETTINGS') ?? '';
	}
}

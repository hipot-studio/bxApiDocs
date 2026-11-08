<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Action;

use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class OpenDashboardAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'open';
	}

	public function getControl(array $rawFields): ?array
	{
		$url = (string)($rawFields['DETAIL_URL'] ?? '');
		if (empty($rawFields['CAN_VIEW_DASHBOARD']) || $url === '')
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.ShareRegistry.GridManager.Instance.openDashboard('" . \CUtil::JSEscape($url) . "')";

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--o-open-new');

		return $result;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_ACTION_OPEN') ?? '';
	}
}

<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Action;

use Bitrix\BIConnector\Internal\Services\Share\ShareStatusResolver;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Offered only for links that still let a guest in or would do so once the author regains the right.
 */
final class DisableShareAction extends FormatMenuAction
{
	private const DISABLEABLE_STATUSES = [
		ShareStatusResolver::STATUS_ACTIVE,
		ShareStatusResolver::STATUS_NO_RIGHT,
	];

	public static function getId(): ?string
	{
		return 'disable';
	}

	public function getControl(array $rawFields): ?array
	{
		if (!in_array($rawFields['STATUS'] ?? null, self::DISABLEABLE_STATUSES, true))
		{
			return null;
		}

		$shareId = (int)($rawFields['ID'] ?? 0);
		$this->onclick = "BX.BIConnector.ShareRegistry.GridManager.Instance.disableShares([{$shareId}])";

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--o-unlink');

		return $result;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_ACTION_DISABLE') ?? '';
	}
}

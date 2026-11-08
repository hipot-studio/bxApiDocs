<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Source;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CUtil;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

final class CreateDatasetSourceAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'createDataset';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_SOURCE_GRID_ACTION_CREATE_DATASET') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$type = (string)($rawFields['TYPE'] ?? '');
		if (
			($rawFields['MODULE'] ?? '') !== 'BI'
			|| ($rawFields['ACTIVE'] ?? '') !== 'Y'
		)
		{
			return null;
		}

		$id = (int)($rawFields['ID'] ?? 0);
		$type = CUtil::JSEscape($type);

		$this->onclick = "BX.BIConnector.ExternalSourceManager.Instance.openDatasetCreation({$id}, '{$type}')";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-add-database');

		return $result;
	}
}

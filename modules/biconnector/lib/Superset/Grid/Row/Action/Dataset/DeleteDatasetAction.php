<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CUtil;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

class DeleteDatasetAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DATASET_GRID_ACTION_DELETE') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$datasetId = (int)($rawFields['ID'] ?? 0);
		$datasetType = CUtil::JSEscape($rawFields['TYPE'] ?? '');
		if (!$datasetId && !$datasetType)
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.ExternalDatasetManager.Instance.deleteDataset({$datasetId}, \"{$datasetType}\")";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-trashcan', true);

		return $result;
	}
}

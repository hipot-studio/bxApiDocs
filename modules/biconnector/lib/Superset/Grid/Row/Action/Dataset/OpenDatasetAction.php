<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

final class OpenDatasetAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'open';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DATASET_GRID_ACTION_OPEN') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$datasetId = $rawFields['ID'] ?? 0;
		$datasetType = $rawFields['TYPE'] ?? '';

		if (!empty($rawFields['IS_SYSTEM']))
		{
			$name = \CUtil::JSEscape($rawFields['NAME']);
			$this->onclick = "BX.BIConnector.DatasetImportV2.Slider.open('system', 0, {tableName: '{$name}'})";

			$result = parent::getControl($rawFields);

			$result['html'] = $this->getMenuItem('--o-open-new');

			return $result;
		}

		if (!$datasetId && !$datasetType)
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.DatasetImportV2.Slider.open(\"{$datasetType}\", $datasetId)";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-open-new');

		return $result;
	}
}

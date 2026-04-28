<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class CreateExternalDatasetAction extends BaseAction
{
	public static function getId(): ?string
	{
		return 'create_external_dataset';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DATASET_GRID_CREATE_EXTERNAL_DATASET_ACTION') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$datasetId = (int)$rawFields['ID'];
		if (!$datasetId)
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.ExternalDatasetManager.Instance.createExternalDataset({$datasetId})";

		return parent::getControl($rawFields);
	}
}

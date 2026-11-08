<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DeleteTableGroupAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'deleteTableGroup';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DATASET_GRID_ACTION_DELETE_GROUP') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		if (($rawFields['ENTITY_TYPE'] ?? '') !== TableGroupRepository::TYPE_GROUP)
		{
			return null;
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_TABLE_RIGHTS))
		{
			return null;
		}

		// A group of the product is not a group of a person: it is deleted by nobody.
		if (($rawFields['TYPE'] ?? '') === TableGroupTable::GROUP_TYPE_SYSTEM)
		{
			return null;
		}

		$groupId = (int)$rawFields['ID'];
		$this->onclick = "BX.BIConnector.ExternalDatasetManager.Instance.deleteGroup({$groupId})";

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--o-trashcan', true);

		return $result;
	}
}

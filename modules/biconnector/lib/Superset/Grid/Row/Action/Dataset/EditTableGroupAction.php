<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * The menu of a group row works with the group it belongs to; creating a new one is the business of
 * the button of the section panel.
 */
final class EditTableGroupAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'editTableGroup';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DATASET_GRID_ACTION_EDIT_GROUP') ?? '';
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

		$groupId = (int)$rawFields['ID'];
		$this->onclick = "BX.BIConnector.ExternalDatasetManager.Instance.editGroup({$groupId})";

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--pencil-60');

		return $result;
	}
}

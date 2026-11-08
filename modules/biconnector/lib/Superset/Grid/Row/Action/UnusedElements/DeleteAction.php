<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\UnusedElements;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;


class DeleteAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'delete';
	}

	public function getControl(array $rawFields): ?array
	{
		$deleteAllElementsPermission = AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DELETE_ALL_UNUSED_ELEMENTS);
		if (!$deleteAllElementsPermission)
		{
			return null;
		}

		$id = (int)$rawFields['EXTERNAL_ID'];
		$type = (string)$rawFields['TYPE'];

		$this->onclick = "BX.BIConnector.UnusedElementsGridManager.Instance.deleteElement([{elementId: {$id}, elementType: '{$type}'}])";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-trashcan', true);

		return $result;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BI_UNUSED_ELEMENTS_GRID_ROW_ACTION_DELETE') ?? '';
	}
}

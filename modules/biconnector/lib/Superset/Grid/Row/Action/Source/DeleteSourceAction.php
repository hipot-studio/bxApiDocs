<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Source;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CUtil;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

class DeleteSourceAction extends FormatMenuAction
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
		return Loc::getMessage('DELETE_SOURCE_ACTION') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		$moduleId = CUtil::JSEscape(($rawFields['MODULE'] ?? ''));
		if ($moduleId !== 'BI')
		{
			return null;
		}

		$this->onclick = "BX.BIConnector.ExternalSourceManager.Instance.deleteSource({$id}, '{$moduleId}')";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-trashcan', true);

		return $result;
	}
}

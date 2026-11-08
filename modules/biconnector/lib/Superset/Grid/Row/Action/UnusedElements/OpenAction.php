<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\UnusedElements;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

class OpenAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'open';
	}

	public function getControl(array $rawFields): ?array
	{
		$openUrl = \CUtil::JSEscape((string)$rawFields['OPEN_URL']);

		$this->onclick = "BX.BIConnector.UnusedElementsGridManager.Instance.openElement(\"{$openUrl}\")";

		$result = parent::getControl($rawFields);

		$result['hideInActionPanel'] = true;

		$result['html'] = $this->getMenuItem('--o-open-new');

		return $result;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BI_UNUSED_ELEMENTS_GRID_ROW_ACTION_OPEN') ?? '';
	}
}

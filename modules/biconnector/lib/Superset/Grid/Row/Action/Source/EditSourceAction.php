<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Source;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CUtil;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;

final class EditSourceAction extends FormatMenuAction
{

	public static function getId(): ?string
	{
		return 'edit';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_SOURCE_GRID_ACTION_EDIT') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		$moduleId = CUtil::JSEscape(($rawFields['MODULE'] ?? ''));

		$this->onclick = "BX.BIConnector.ExternalSourceManager.Instance.openSourceDetail({$id}, '{$moduleId}')";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--edit-l');

		return $result;
	}
}
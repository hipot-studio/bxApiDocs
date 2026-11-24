<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Action;

use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DeleteRuleAction extends FormatMenuAction
{
	public static function getId(): ?string
	{
		return 'delete';
	}

	public function getControl(array $rawFields): ?array
	{
		$ruleId = (int)($rawFields['ID'] ?? 0);
		if ($ruleId <= 0)
		{
			return null;
		}

		$this->onclick = RowRuleActionDataProvider::GRID_MANAGER . ".remove({$ruleId})";

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
		return Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_ACTION_DELETE') ?? '';
	}
}

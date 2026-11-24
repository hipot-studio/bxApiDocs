<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;

final class RowRuleActionDataProvider extends DataProvider
{
	public const GRID_MANAGER = 'BX.BIConnector.RowRulesGrid.Instance';

	public function prepareActions(): array
	{
		return [
			new OpenRuleAction(),
			new DeleteRuleAction(),
		];
	}
}

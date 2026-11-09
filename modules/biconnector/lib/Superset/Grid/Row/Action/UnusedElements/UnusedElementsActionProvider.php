<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\UnusedElements;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\Main\Grid\Row\Action\DataProvider;

class UnusedElementsActionProvider extends DataProvider
{
	public function prepareActions(): array
	{
		$actions = [];

		if (AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			$actions[] = new OpenAction();
		}

		$actions[] = new DeleteAction();

		return $actions;
	}
}

<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;

class ShareRegistryActionProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new OpenDashboardAction(),
			new OpenSettingsAction(),
			new DisableShareAction(),
		];
	}
}

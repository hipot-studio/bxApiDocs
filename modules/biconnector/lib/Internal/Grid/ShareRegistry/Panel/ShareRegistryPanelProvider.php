<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Panel;

use Bitrix\BIConnector\Internal\Grid\ShareRegistry\Panel\Action\DisableSharesAction;
use Bitrix\Main\Grid\Panel\Action\DataProvider;

final class ShareRegistryPanelProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new DisableSharesAction(),
		];
	}
}

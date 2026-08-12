<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Row\Action\DataProvider;

class StorageListDataProvider extends DataProvider
{
	/**
	 * @return BaseAction[]
	 */
	public function prepareActions(): array
	{
		return [
			new DeleteAction(),
		];
	}
}

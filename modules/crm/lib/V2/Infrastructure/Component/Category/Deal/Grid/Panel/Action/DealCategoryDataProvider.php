<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Panel\Action;

use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Action\ForAllCheckboxAction;

final class DealCategoryDataProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new EditAction(),
			new DeleteAction(),
			new ForAllCheckboxAction(),
		];
	}
}

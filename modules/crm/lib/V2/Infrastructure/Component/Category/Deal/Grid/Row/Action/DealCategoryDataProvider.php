<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;

final class DealCategoryDataProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new EditAction(),
			new EditStagesAction(),
			new DeleteAction(),
		];
	}
}

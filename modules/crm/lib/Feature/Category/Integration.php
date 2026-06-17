<?php

namespace Bitrix\Crm\Feature\Category;

use Bitrix\Main\Localization\Loc;

final class Integration extends BaseCategory
{
	public function getName(): string
	{
		return Loc::getMessage('CATEGORY_INTEGRATION_NAME');
	}

	public function getSort(): int
	{
		return 1000;
	}
}

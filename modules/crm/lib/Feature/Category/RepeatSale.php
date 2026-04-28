<?php

namespace Bitrix\Crm\Feature\Category;

use Bitrix\Main\Localization\Loc;

class RepeatSale extends BaseCategory
{

	public function getName(): string
	{
		return Loc::getMessage('CATEGORY_REPEAT_SALE_NAME');
	}

	public function getSort(): int
	{
		return 1000;
	}
}

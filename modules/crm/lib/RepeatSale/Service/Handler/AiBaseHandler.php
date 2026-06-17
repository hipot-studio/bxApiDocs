<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use CCrmOwnerType;

abstract class AiBaseHandler extends BaseHandler
{
	public function getAvailableEntityTypeIds(): array
	{
		return [
			CCrmOwnerType::Deal,
		];
	}
}

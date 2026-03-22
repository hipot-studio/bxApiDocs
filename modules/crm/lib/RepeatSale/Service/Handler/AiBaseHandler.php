<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

abstract class AiBaseHandler extends BaseHandler
{
	public function getAvailableEntityTypeIds(): array
	{
		return [
			\CCrmOwnerType::Deal,
		];
	}
}

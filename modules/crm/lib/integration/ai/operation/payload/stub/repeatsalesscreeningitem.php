<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Web\Json;

final class RepeatSalesScreeningItem implements StubInterface
{
	public function makeStub(): string
	{
		return Json::encode([
			'category' => '8 march',
			'isRepeatSalePossible' => true,
		]);
	}
}

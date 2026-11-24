<?php

namespace Bitrix\AI\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class MonthlyPoolAggregate extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}

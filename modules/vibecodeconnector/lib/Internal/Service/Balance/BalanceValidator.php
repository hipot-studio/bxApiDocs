<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

final class BalanceValidator
{
	public function isValid(string $value): bool
	{
		return preg_match('/^[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
	}
}

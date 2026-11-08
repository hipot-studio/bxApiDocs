<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

final class UserCodeResult
{
	public function __construct(public readonly array $codes, public readonly bool $degraded)
	{
	}
}

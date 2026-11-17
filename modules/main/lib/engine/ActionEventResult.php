<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine;

use Bitrix\Main\EventResult;

class ActionEventResult extends EventResult
{
	public const SKIP_ACTION = 'ActionEventResult::skipAction';

	public static function skipAction(?object $handler = null): self
	{
		return new self(self::SKIP_ACTION, handler: $handler);
	}
}

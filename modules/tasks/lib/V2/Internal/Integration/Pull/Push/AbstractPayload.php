<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

abstract class AbstractPayload
{
	abstract public function toArray(): array;

	abstract public function getCommand(): string;
}

<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Command;

abstract class AbstractPayload
{
	abstract public function getCommand(): string;
	abstract public function toArray(): array;
}

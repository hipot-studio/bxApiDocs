<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Dto;

abstract class BaseDto
{
	abstract public static function fromArray(array $args): self;
}

<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

use Bitrix\Main\Result;

interface EventValidatorInterface
{
	public function validate(Event $event): Result;
}

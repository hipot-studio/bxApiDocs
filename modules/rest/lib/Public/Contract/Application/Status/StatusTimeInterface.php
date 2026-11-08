<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Contract\Application\Status;

interface StatusTimeInterface
{
	public function getCurrentTimestamp(): int;

	public function getTimezoneOffset(): int;
}

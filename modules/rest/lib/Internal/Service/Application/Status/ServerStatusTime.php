<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Status;

use Bitrix\Rest\Public\Contract\Application\Status\StatusTimeInterface;

final class ServerStatusTime implements StatusTimeInterface
{
	public function getCurrentTimestamp(): int
	{
		return time();
	}

	public function getTimezoneOffset(): int
	{
		return (int)\CTimeZone::GetOffset();
	}
}

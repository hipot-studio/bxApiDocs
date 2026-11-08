<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Contract\Application\Status;

interface ApplicationHoldStateInterface
{
	public function isHeld(string $clientId): bool;
}

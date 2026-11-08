<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Status;

use Bitrix\Rest\Engine\Access\HoldEntity;
use Bitrix\Rest\Public\Contract\Application\Status\ApplicationHoldStateInterface;

final class ApplicationHoldState implements ApplicationHoldStateInterface
{
	public function isHeld(string $clientId): bool
	{
		return HoldEntity::is(HoldEntity::TYPE_APP, $clientId);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\VibeBalance;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\BalancePullMessage;

final class BalanceSyncCoordinator
{
	public function __construct(
		private readonly BalancePullQueueGuard $queueGuard = new BalancePullQueueGuard(),
	)
	{
	}

	public function request(UserEventTarget $target, int $version): bool
	{
		if ($version < 0)
		{
			throw new \InvalidArgumentException('Balance version must not be negative');
		}

		if (!Feature::isEnabled(VibeBalance::class))
		{
			return true;
		}

		return $this->queueGuard->tryEnqueue(new BalancePullMessage(
			pairingIss: $target->iss,
			endpointUrl: $target->endpointUrl,
			requestedVersion: $version,
		));
	}
}

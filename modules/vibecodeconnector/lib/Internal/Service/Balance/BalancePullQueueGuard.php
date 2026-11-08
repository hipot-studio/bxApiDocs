<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Vibecodeconnector\Internal\Service\Balance\Storage\BalanceOptionStateStorage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\BalancePullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\QueueId;

class BalancePullQueueGuard
{
	public function __construct(
		private readonly BalanceOptionStateStorage $storage = new BalanceOptionStateStorage(),
	)
	{
	}

	public function tryEnqueue(BalancePullMessage $message): bool
	{
		try
		{
			$state = $this->storage->markRequested($message->requestedVersion);
			if ($state === null)
			{
				return true;
			}

			$message->requestedVersion = $state->requestedVersion;
			$this->sendMessage($message);
			$this->storage->markEnqueued($message->requestedVersion);

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function sendMessage(BalancePullMessage $message): void
	{
		$message->send(QueueId::BalancePull->value);
	}
}

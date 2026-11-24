<?php

namespace Bitrix\Mobile\Internal\Onboarding\Service;

use Bitrix\Mobile\Internal\Onboarding\Result\PushDeliveryResult;
use Bitrix\Mobile\Push\ApplicationChannel;
use Bitrix\Mobile\Push\Channel;
use Bitrix\Mobile\Push\DeviceChannel;
use Bitrix\Mobile\Push\Message;
use Bitrix\Mobile\Push\Sender;

/**
 * Sends a push of the campaign through the immediate channels and reports what each of
 * them did. The channels are the ones Sender::sendImmediate() uses; they are asked one
 * by one because the merged Result of that facade cannot tell a partial delivery from a
 * failed one, and the pipeline decides on the difference: a push read as "not sent"
 * leaves the campaign state unwritten and goes out again on the next pass of the same
 * send window.
 *
 * The channels are built per call - their files require the pull module, and the caller
 * loads it before asking for a delivery.
 */
class PushDeliveryService
{
	private const CHANNEL_APPLICATION = 'application';
	private const CHANNEL_DEVICE = 'device';

	public function deliver(int $userId, Message $message): PushDeliveryResult
	{
		$result = new PushDeliveryResult();

		foreach ($this->channels() as $name => $channel)
		{
			$result->registerChannel($name, Sender::send($userId, $message, [$channel]));
		}

		return $result;
	}

	/**
	 * @return array<string, Channel>
	 */
	protected function channels(): array
	{
		return [
			self::CHANNEL_APPLICATION => new ApplicationChannel(),
			self::CHANNEL_DEVICE => new DeviceChannel(),
		];
	}
}

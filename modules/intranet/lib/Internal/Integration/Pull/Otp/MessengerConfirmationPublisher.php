<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Pull\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Pull\Config;
use Bitrix\Pull\Event;
use Bitrix\Pull\Model\Channel;

final class MessengerConfirmationPublisher
{
	public const MODULE_ID = 'security';
	public const COMMAND = 'messengerConfirmationStatus';

	public const DEFAULT_CODE_LIFETIME_SECONDS = 30;

	private const CHANNEL_TAG_LENGTH = 32;

	public function getCodeLifetime(): int
	{
		$interval = (int)Option::get('security', 'totp_interval', (string)self::DEFAULT_CODE_LIFETIME_SECONDS);

		return $interval > 0 ? $interval : self::DEFAULT_CODE_LIFETIME_SECONDS;
	}

	public function openChannel(): ?MessengerConfirmationChannel
	{
		if (!Loader::includeModule('pull'))
		{
			return null;
		}

		try
		{
			$channelTag = Random::getString(self::CHANNEL_TAG_LENGTH, true);
			if (preg_match(MessengerConfirmationState::CHANNEL_TAG_PATTERN, $channelTag) !== 1)
			{
				return null;
			}

			$connectionConfig = Config::get(['CHANNEL' => Channel::createWithTag($channelTag), 'JSON' => true]);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($connectionConfig)
			? new MessengerConfirmationChannel($channelTag, $connectionConfig)
			: null;
	}

	public function publish(MessengerConfirmationState $state): bool
	{
		if ($state->channelTag === null || !$state->status->isTerminal() || !Loader::includeModule('pull'))
		{
			return false;
		}

		try
		{
			return Event::add([Channel::createWithTag($state->channelTag)], [
				'module_id' => self::MODULE_ID,
				'command' => self::COMMAND,
				'expiry' => $this->getCodeLifetime(),
				'params' => $this->buildParams($state),
			]) === true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function buildParams(MessengerConfirmationState $state): array
	{
		return [
			'requestId' => $state->requestId,
			'status' => $state->status->value,
			'expiresAt' => $state->expiresAt,
		];
	}
}

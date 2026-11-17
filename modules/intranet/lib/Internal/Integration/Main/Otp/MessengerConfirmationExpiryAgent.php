<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Internal\Repository\Otp\MessengerConfirmationStateRepository;
use Bitrix\Main\DI\ServiceLocator;

final class MessengerConfirmationExpiryAgent
{
	private const RETRY_INTERVAL_SECONDS = 30;

	public static function schedule(MessengerConfirmationState $state): bool
	{
		return (int)\CAgent::AddAgent(
			self::getName($state->requestId, $state->expiresAt),
			'intranet',
			'N',
			self::RETRY_INTERVAL_SECONDS,
			'',
			'Y',
			\ConvertTimeStamp($state->expiresAt, 'FULL'),
		) > 0;
	}

	public static function run(string $requestId, int $expiresAt): string
	{
		if (
			preg_match(MessengerConfirmationState::REQUEST_ID_PATTERN, $requestId) !== 1
			|| time() >= $expiresAt + MessengerConfirmationStateRepository::RETENTION_AFTER_EXPIRATION_SECONDS
		)
		{
			return '';
		}

		try
		{
			$coordinator = ServiceLocator::getInstance()->get('intranet.service.otp.messenger_confirmation');
			if ($coordinator->expire($requestId))
			{
				return '';
			}
		}
		catch (\Throwable)
		{
		}

		return self::getName($requestId, $expiresAt);
	}

	private static function getName(string $requestId, int $expiresAt): string
	{
		return sprintf('\\%s::run(\'%s\', %d);', self::class, $requestId, $expiresAt);
	}
}

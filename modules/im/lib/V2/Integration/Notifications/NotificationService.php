<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Notifications;

use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\PhoneNumber;
use Bitrix\Notifications;

/**
 * Wrapper for the notifications module (UNC) used to send SMS from Bitrix24.
 */
class NotificationService
{
	/**
	 * Check whether the notifications module is installed, the service is available and connected.
	 */
	public static function isAvailable(): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return false;
		}

		return Notifications\Account::isServiceAvailable() && Notifications\Account::isConnected();
	}

	/**
	 * Enqueue an SMS message via the notifications module using a registered UNC template.
	 *
	 * @param string $phoneE164 Phone number in E.164 format.
	 * @param string $templateCode UNC template code registered at the provider.
	 * @param array $placeholders Key-value pairs for template substitution.
	 * @return Result
	 */
	public static function sendSms(string $phoneE164, string $templateCode, array $placeholders): Result
	{
		$result = new Result();

		if (!self::isAvailable())
		{
			return $result->addError(new NotificationError(NotificationError::SERVICE_UNAVAILABLE));
		}

		$enqueueResult = Notifications\Model\Message::create([
			'PHONE_NUMBER' => $phoneE164,
			'TEMPLATE_CODE' => $templateCode,
			'LANGUAGE_ID' => LANGUAGE_ID,
			'PLACEHOLDERS' => $placeholders,
		])->enqueue();

		if (!$enqueueResult->isSuccess())
		{
			return $result->addErrors($enqueueResult->getErrors());
		}

		return $result;
	}

	/**
	 * Parse a phone number string and return it in E.164 format.
	 *
	 * @return string|null E.164 phone string or null when the number is invalid.
	 */
	public static function formatPhoneE164(string $phone): ?string
	{
		$parsedPhone = PhoneNumber\Parser::getInstance()->parse($phone);
		if (!$parsedPhone->isValid())
		{
			return null;
		}

		return $parsedPhone->format(PhoneNumber\Format::E164);
	}
}

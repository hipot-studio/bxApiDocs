<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Transport;

use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\GuestInviteError;

/**
 * Default SMS transport: sends via UNC (notifications module).
 */
class DefaultGuestInviteSmsTransport implements GuestInviteSmsTransportInterface
{
	private const SMS_TEMPLATE_CODE = 'IM_GUEST_CHAT_INVITE';

	/** @inheritDoc */
	public function sendSms(string $phoneE164, string $url, string $chatTitle): Result
	{
		$result = new Result();

		if (!NotificationService::isAvailable())
		{
			return $result->addError(new GuestInviteError(GuestInviteError::SMS_NOT_CONNECTED));
		}

		$sendResult = NotificationService::sendSms($phoneE164, self::SMS_TEMPLATE_CODE, [
			'CHAT_TITLE' => mb_substr(trim($chatTitle), 0, 100),
			'URL' => $url,
		]);

		if (!$sendResult->isSuccess())
		{
			return $result->addError(new GuestInviteError(GuestInviteError::SMS_SEND_FAILED));
		}

		return $result;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Transport;

use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\GuestInviteError;

/**
 * Default email transport: sends via CEvent.
 */
class DefaultGuestInviteEmailTransport implements GuestInviteEmailTransportInterface
{
	private const MAIL_EVENT_TYPE = 'IM_GUEST_INVITATION';

	/** @inheritDoc */
	public function sendEmail(string $email, string $url, ?string $name, string $chatTitle): Result
	{
		$result = new Result();

		$fields = [
			'EMAIL_TO' => $email,
			'GUEST_NAME' => $name ?? '',
			'CHAT_TITLE' => $chatTitle,
			'INVITATION_LINK' => $url,
			'SERVER_NAME' => \Bitrix\Main\Context::getCurrent()?->getServer()?->getHttpHost() ?? '',
		];

		$sendResult = \CEvent::SendImmediate(
			self::MAIL_EVENT_TYPE,
			SITE_ID,
			$fields,
		);

		if ($sendResult !== 'Y')
		{
			return $result->addError(new GuestInviteError(GuestInviteError::EMAIL_SEND_FAILED));
		}

		return $result;
	}
}

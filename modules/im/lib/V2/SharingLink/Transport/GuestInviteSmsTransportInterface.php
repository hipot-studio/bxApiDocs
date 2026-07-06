<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Transport;

use Bitrix\Im\V2\Result;

/**
 * Transport abstraction for sending guest chat invitations via SMS.
 */
interface GuestInviteSmsTransportInterface
{
	/**
	 * Send an SMS invitation to join a guest chat.
	 *
	 * @param string $phoneE164 Phone number in E.164 format.
	 * @param string $url Invitation link.
	 * @param string $chatTitle Chat display title.
	 * @return Result
	 */
	public function sendSms(string $phoneE164, string $url, string $chatTitle): Result;
}

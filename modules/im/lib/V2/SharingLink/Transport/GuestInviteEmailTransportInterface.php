<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Transport;

use Bitrix\Im\V2\Result;

/**
 * Transport abstraction for sending guest chat invitations via email.
 */
interface GuestInviteEmailTransportInterface
{
	/**
	 * Send an email invitation to join a guest chat.
	 *
	 * @param string $email Recipient email address.
	 * @param string $url Invitation link.
	 * @param string|null $name Recipient display name.
	 * @param string $chatTitle Chat display title.
	 * @return Result
	 */
	public function sendEmail(string $email, string $url, ?string $name, string $chatTitle): Result;
}

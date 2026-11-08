<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Main\Result;

/**
 * Message access seam of the sender avatar flow: a successful result opens the message to the user,
 * any other outcome denies it. The implementation over the public access service is supplied by a
 * layer that is allowed to depend on it.
 */
interface MessageAccessCheck
{
	// Mirrors the refusal code of the public mail message access service, which this layer must not reach.
	public const ERROR_ACCESS_DENIED = 'MAIL_MESSAGE_ACCESS_DENIED';

	public function check(int $mailboxId, int $messageId, int $userId, string $accessToken): Result;
}

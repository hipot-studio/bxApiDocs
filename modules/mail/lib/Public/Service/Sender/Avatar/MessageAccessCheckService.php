<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Sender\Avatar;

use Bitrix\Mail\Internal\Service\Sender\Avatar\MessageAccessCheck;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main\Result;

final class MessageAccessCheckService implements MessageAccessCheck
{
	public function check(int $mailboxId, int $messageId, int $userId, string $accessToken): Result
	{
		return (new MessageAccessService())->resolve($mailboxId, $messageId, $userId, $accessToken);
	}
}

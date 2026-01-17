<?php

namespace Bitrix\MailMobile\Internal\Integration\Main;

use Bitrix\Main\Mail\Sender;

class SenderProvider
{
	public function getUserAvailableSenders(?int $userId = null): array
	{
		return Sender::prepareUserMailboxes($userId);
	}
}

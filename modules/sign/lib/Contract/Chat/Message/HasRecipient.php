<?php

namespace Bitrix\Sign\Contract\Chat\Message;

use Bitrix\Sign\Contract\Chat\Message;

interface HasRecipient extends Message
{
	// who received the document
	public function getRecipientUserId(): int;

	public function getRecipientName(): string;
}

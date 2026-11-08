<?php

namespace Bitrix\Sign\Contract\Chat\Message;

use Bitrix\Sign\Contract\Chat\Message;

interface HasUserMessage extends Message
{
	// text written by the document sender, as it was entered
	public function getUserMessage(): ?string;
}

<?php

namespace Bitrix\Mail\Helper\Dto;

use Bitrix\Mail\Helper\Dto\MailMessage;

class MailMessageChain
{
	/** @var MailMessage[] */
	public array $list = [];
	public int $lastIncomingId = 0;
	public array $properties = [];
}
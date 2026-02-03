<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\Dto\MailMessageChain;

interface MailMessageChainProviderInterface
{
	public function getChain(int $messageId): MailMessageChain;

	public function getMessage(int $id, bool $takeBody = false, bool $takeFiles = false): MailMessage;

	public function replaceAttachmentPlaceholders(string $body, array $attachments): string;
}
<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\Mapper;

use Bitrix\Mail\Item\Message;
use Bitrix\Main\Text\Emoji;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Service\EmailLinkService;

class EmailMapper
{
	public function __construct(
		private readonly EmailLinkService $emailLinkService,
	)
	{

	}

	public function mapFromEntity(Message $message, int $taskId): Email
	{
		return Email::mapFromArray([
			'id' => $message->getId(),
			'taskId' => $taskId,
			'mailboxId' => $message->getMailboxId(),
			'title' => Emoji::decode($message->getSubject()),
			'body' => Emoji::decode($message->getBody()),
			'from' => $message->getFrom(),
			'dateTs' => $message->getDate()->getTimestamp(),
			'link' => $this->emailLinkService->getLink($message->getId()),
		]);
	}

	public function mapFromArray(array $message): Email
	{
		return Email::mapFromArray($message);
	}
}

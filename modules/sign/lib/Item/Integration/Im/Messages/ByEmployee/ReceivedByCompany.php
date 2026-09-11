<?php

namespace Bitrix\Sign\Item\Integration\Im\Messages\ByEmployee;

use Bitrix\Sign\Contract\Chat\Message\HasRecipient;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Integration\Im\Message;

class ReceivedByCompany extends Message\ByEmployee implements HasRecipient
{
	public function __construct(
		int $fromUser,
		int $toUser,
		private readonly int $recipientUserId,
		private readonly string $recipientName,
		Document $document,
		string $link,
	)
	{
		parent::__construct($fromUser, $toUser);
		$this->document = $document;
		$this->link = $link;
	}

	public function getStageId(): string
	{
		return 'byEmployeeReceivedByCompany';
	}

	public function getRecipientUserId(): int
	{
		return $this->recipientUserId;
	}

	public function getRecipientName(): string
	{
		return $this->recipientName;
	}

	public function getFallbackText(): string
	{
		return $this->getLocalizedFallbackMessage(
			'SIGN_CALLBACK_CHAT_BY_EMPLOYEE_RECEIVED_BY_COMPANY',
			[
				'#DOC_NAME#' => $this->getDocumentName($this->getDocument()),
				'#SIGN_URL#' => $this->getLink(),
				'#RECEIVER_NAME#' => $this->getRecipientName(),
			]
		);
	}
}

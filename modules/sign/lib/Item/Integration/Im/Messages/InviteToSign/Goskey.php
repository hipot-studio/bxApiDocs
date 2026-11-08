<?php

namespace Bitrix\Sign\Item\Integration\Im\Messages\InviteToSign;

use Bitrix\Sign\Contract\Chat\Message\HasMemberKey;
use Bitrix\Sign\Contract\Chat\Message\HasUserMessage;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Integration\Im\Message;

class Goskey extends Message\WithInitiator implements HasUserMessage, HasMemberKey
{
	use Message\UserMessageTrait;

	public function __construct(
		int $fromUser,
		int $toUser,
		int $initiatorUserId,
		string $initiatorName,
		Document $document,
		string $link,
		?string $userMessage = null,
	)
	{
		parent::__construct($fromUser, $toUser, $initiatorUserId, $initiatorName);
		$this->document = $document;
		$this->link = $link;
		$this->userMessage = $userMessage;
	}

	public function getStageId(): string
	{
		return $this->getInitiatorUserId() === $this->getUserTo()
			? 'inviteEmployeeGosKeyV2'
			: 'inviteEmployeeGosKeyWithInitiator'
		;
	}

	public function getHelpId(): ?int
	{
		return 19740842;
	}

	public function getFallbackText(): string
	{
		$messageId = $this->getInitiatorUserId() === $this->getUserTo()
			? 'SIGN_CALLBACK_CHAT_INVITE_EMPLOYEE_GOS_KEY_MSGVER_3'
			: 'SIGN_CALLBACK_CHAT_INVITE_EMPLOYEE_GOS_KEY_INITIATOR'
		;

		return $this->appendUserMessageBlock(
			$this->getLocalizedFallbackMessage(
				$messageId,
				[
					'#DOC_NAME#' => $this->getDocumentName($this->getDocument()),
					'#SIGN_URL#' => $this->getLink(),
					'#HELPDESK_URL#' => 'https://helpdesk.bitrix24.ru/open/' . $this->getHelpId(),
					'#INITIATOR_NAME#' => $this->getInitiatorName(),
				]
			)
		);
	}
}

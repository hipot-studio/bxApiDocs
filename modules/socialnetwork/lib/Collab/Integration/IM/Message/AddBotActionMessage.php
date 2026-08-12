<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;

class AddBotActionMessage implements ActionMessageInterface
{
	use MessageTrait;

	protected int $collabId;
	protected int $senderId;

	public function __construct(int $collabId, int $senderId)
	{
		$this->collabId = $collabId;
		$this->senderId = $senderId;
	}

	public function send(array $recipientIds = [], array $parameters = []): int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		if (empty($recipientIds))
		{
			return 0;
		}

		$skipChat = $parameters['skipChat'] ?? false;

		if (!$skipChat)
		{
			$this->addUsersToChat($this->collabId, $parameters, ...$recipientIds);
		}

		$withoutMessage = $parameters['withoutMessage'] ?? false;

		if ($withoutMessage)
		{
			return 0;
		}

		$recipientNames = [];
		foreach ($recipientIds as $recipientId)
		{
			$recipientNames[] = $this->getName($this->senderId, $recipientId, $this->collabId);
		}

		if (empty($recipientNames))
		{
			return 0;
		}

		$userNames = implode(', ', $recipientNames);
		$senderName = $this->getName($this->senderId, $this->senderId, $this->collabId);

		$hasMultipleRecipients = count($recipientNames) > 1;

		$message = (string)Loc::getMessage(
			$this->getMessageCode($hasMultipleRecipients),
			[
				'#SENDER_NAME#' => $senderName,
				'#RECIPIENT#' => $userNames,
			],
		);

		return $this->sendMessage(
			message: $message,
			senderId: $this->senderId,
			groupId: $this->collabId,
			silent: $this->resolveCounterSilent(ActionType::AddBot, $this->collabId, self::SILENT_OFF),
		);
	}

	private function getMessageCode(bool $hasMultipleRecipients): string
	{
		$senderGender = $this->getGender($this->senderId);

		if ($senderGender === 'M')
		{
			return
				$hasMultipleRecipients
					? 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_M_MANY'
					: 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_M'
			;
		}

		if ($senderGender === 'F')
		{
			return
				$hasMultipleRecipients
					? 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_F_MANY'
					: 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_F'
			;
		}

		return
			$hasMultipleRecipients
				? 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_N_MANY'
				: 'SOCIALNETWORK_COLLAB_CHAT_BOT_ADD_N'
		;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\UserToGroupTable;

class AddGuestActionMessage implements ActionMessageInterface
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

		$recipientNames = $this->collectRecipientNames($recipientIds, $parameters);

		// Name collection carries the acceptance of a recipient who is the sender himself, so suppression
		// cuts in only below it: what is dropped is the system text, not the acceptance.
		if ($parameters[ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER] ?? false)
		{
			return 0;
		}

		if (empty($recipientNames))
		{
			return 0;
		}

		$userNames = implode(', ', $recipientNames);
		$senderName = $this->getName($this->senderId, $this->senderId, $this->collabId);

		$count = count($recipientNames);
		$key = 'SOCIALNETWORK_COLLAB_CHAT_USER_ADD_GUEST' . $this->getGenderSuffix($this->senderId);
		$key .= $count > 1 ? '_MANY' : '';

		$message = (string)Loc::getMessage(
			$key,
			[
				'#SENDER_NAME#' => $senderName,
				'#RECIPIENT#' => $userNames,
			],
		);

		return $this->sendMessage(
			message: $message,
			senderId: $this->senderId,
			groupId: $this->collabId,
			silent: $this->resolveCounterSilent(ActionType::AddGuest, $this->collabId, self::SILENT_OFF),
		);
	}

	/**
	 * A guest that came with a department or a team is left to the summary naming that node, exactly as an
	 * employee is: one save of the composition speaks once, and the chat membership above is done either way.
	 *
	 * @return string[]
	 */
	private function collectRecipientNames(array $recipientIds, array $parameters): array
	{
		$initiatedByType = $parameters['initiatedByType'] ?? UserToGroupTable::INITIATED_BY_GROUP;
		if ($initiatedByType === UserToGroupTable::INITIATED_BY_STRUCTURE)
		{
			return [];
		}

		$recipientNames = [];
		foreach ($recipientIds as $recipientId)
		{
			if ($recipientId === $this->senderId)
			{
				$factory = ActionMessageFactory::getInstance();
				$acceptMessage = $factory->getActionMessage(ActionType::AcceptUser, $this->collabId, $this->senderId);

				$acceptMessage->send(parameters: $parameters);

				continue;
			}

			$recipientNames[] = $this->getName($this->senderId, $recipientId, $this->collabId);
		}

		return $recipientNames;
	}
}

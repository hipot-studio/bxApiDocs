<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Feature;

class AddUserActionMessage implements ActionMessageInterface
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

		// Department-initiated adds get a project-phrased announcement (ProjectDepartmentMembersAdded, sent
		// from EventHandler::announceProjectDepartment), so the per-user list is skipped here — the users
		// are already added to the chat above.
		$initiatedByType = $parameters['initiatedByType'] ?? UserToGroupTable::INITIATED_BY_GROUP;
		if ($initiatedByType === UserToGroupTable::INITIATED_BY_STRUCTURE)
		{
			return 0;
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

		if (empty($recipientNames))
		{
			return 0;
		}

		$userNames = implode(', ', $recipientNames);
		$senderName = $this->getName($this->senderId, $this->senderId, $this->collabId);

		$count = count($recipientNames);
		$key = 'SOCIALNETWORK_COLLAB_CHAT_USER_ADD' . $this->getGenderSuffix($this->senderId);
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
			silent: $this->resolveCounterSilent(ActionType::AddUser, $this->collabId, Feature::isNewProjectsOn() ? self::SILENT_WITH_RECENT : self::SILENT_OFF),
		);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageFactory;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectDepartmentMembersAdded;

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

		$nodes = $parameters[ActionMessageBuffer::STRUCTURE_NODES_PARAMETER] ?? [];

		if (empty($recipientIds) && empty($nodes))
		{
			return 0;
		}

		$skipChat = $parameters['skipChat'] ?? false;

		if (!$skipChat && !empty($recipientIds))
		{
			$this->addUsersToChat($this->collabId, $parameters, ...$recipientIds);
		}

		$recipientNames = $this->collectRecipientNames($recipientIds, $parameters);

		// Name collection carries the acceptance of a recipient who is the sender himself, so suppression
		// cuts in only below it: what is dropped is the summary and the system text, not the acceptance.
		if ($parameters[ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER] ?? false)
		{
			return 0;
		}

		if (!empty($nodes))
		{
			return $this->sendMembersSummary(
				new ProjectDepartmentMembersAdded($recipientNames, $nodes),
				$this->collabId,
				$this->resolveSilent() !== self::SILENT_OFF,
			);
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
			silent: $this->resolveSilent(),
		);
	}

	/**
	 * Members that came with a department or a team are announced by the node name, so they are not
	 * listed one by one: the node may hold hundreds of people and its members keep arriving in batches.
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

	private function resolveSilent(): int
	{
		return $this->resolveCounterSilent(
			ActionType::AddUser,
			$this->collabId,
			Feature::isNewProjectsOn() ? self::SILENT_WITH_RECENT : self::SILENT_OFF,
		);
	}
}

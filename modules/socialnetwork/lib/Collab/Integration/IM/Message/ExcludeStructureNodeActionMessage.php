<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionMessageBuffer;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectDepartmentMembersRemoved;

/**
 * Announces everyone removed by one composition edit as a single project summary.
 */
class ExcludeStructureNodeActionMessage implements ActionMessageInterface
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

		$recipientNames = [];
		foreach ($recipientIds as $recipientId)
		{
			$recipientNames[] = $this->getName($this->senderId, $recipientId, $this->collabId);
		}
		if (!empty($recipientIds))
		{
			$this->deleteUsersFromChat($this->collabId, ...$recipientIds);
		}

		if ($parameters[ActionMessageBuffer::WITHOUT_MESSAGE_PARAMETER] ?? false)
		{
			return 0;
		}

		return $this->sendMembersSummary(
			new ProjectDepartmentMembersRemoved($recipientNames, $nodes),
			$this->collabId,
			$this->resolveSilent() !== self::SILENT_OFF,
		);
	}

	/**
	 * The summary asks the counter the same question the per-person removal message asks (ExcludeUserActionMessage):
	 * both announce people leaving the project, and the impersonal form of it must not be the louder one.
	 */
	private function resolveSilent(): int
	{
		return $this->resolveCounterSilent(
			ActionType::ExcludeUser,
			$this->collabId,
			Feature::isNewProjectsOn() ? self::SILENT_WITH_RECENT : self::SILENT_OFF,
		);
	}
}

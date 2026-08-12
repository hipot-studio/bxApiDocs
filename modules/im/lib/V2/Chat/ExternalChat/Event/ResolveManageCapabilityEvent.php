<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\Chat\ExternalChat\Event;

/**
 * Batch capability resolution for a set of external chats of the same entity type.
 * Result: map chatId => bool (may this user manage the underlying entity).
 */
class ResolveManageCapabilityEvent extends Event
{
	/**
	 * @param int[] $chatIds
	 */
	public function __construct(string $entityType, int $userId, array $chatIds)
	{
		parent::__construct($entityType, ['userId' => $userId, 'chatIds' => $chatIds]);
	}

	protected function getActionName(): string
	{
		return 'ResolveManageCapability';
	}

	public function getUserId(): int
	{
		return (int)$this->parameters['userId'];
	}

	/**
	 * @return int[]
	 */
	public function getChatIds(): array
	{
		return $this->parameters['chatIds'];
	}

	/**
	 * @return array<int, bool> map chatId => canManage; empty when no handler responded.
	 */
	public function getCapabilities(): array
	{
		if (!$this->hasResult())
		{
			return [];
		}

		$capabilities = $this->getParameterFromResult('capabilities');
		if (!is_array($capabilities))
		{
			return [];
		}

		return $capabilities;
	}
}

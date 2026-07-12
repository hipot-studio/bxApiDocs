<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Integration\Pull\PushService;
use Bitrix\Socialnetwork\Internals\EventService\EventDictionary;
use Bitrix\Socialnetwork\Internals\EventService\Push\PushEventDictionary;
use Bitrix\Socialnetwork\Internals\EventService\Service as EventService;

class RealtimePublisher
{
	private const MODULE_ID = 'socialnetwork';

	public function publishPinChanged(int $groupId, int $userId, bool $isPinned): void
	{
		$this->sendPersonalEvent(
			[$userId],
			PushEventDictionary::EVENT_WORKGROUP_PIN_CHANGED,
			[
				'GROUP_ID' => $groupId,
				'USER_ID' => $userId,
				'ACTION' => $isPinned ? 'pin' : 'unpin',
			],
		);
	}

	public function publishFavoriteChanged(int $groupId, int $userId): void
	{
		$this->sendPersonalEvent(
			[$userId],
			PushEventDictionary::EVENT_WORKGROUP_FAVORITES_CHANGED,
			[
				'GROUP_ID' => $groupId,
				'USER_ID' => $userId,
			],
		);
	}

	/**
	 * Keeps the current moderation notification path unchanged.
	 *
	 * @param int[] $recipientIds
	 */
	public function publishMemberRequestConfirm(int $groupId, array $recipientIds): void
	{
		$this->queueEvent(
			EventDictionary::EVENT_WORKGROUP_MEMBER_REQUEST_CONFIRM,
			[
				'GROUP_ID' => $groupId,
				'RECEPIENTS' => $recipientIds,
			],
		);
	}

	/**
	 * Phase 1 keeps the existing transport contract unchanged and only centralizes it.
	 *
	 * @param int[] $userIds
	 */
	protected function sendPersonalEvent(array $userIds, string $command, array $params): void
	{
		if (empty($userIds))
		{
			return;
		}

		PushService::addEvent(
			$userIds,
			[
				'module_id' => self::MODULE_ID,
				'command' => $command,
				'params' => $params,
			]
		);
	}

	protected function queueEvent(string $type, array $data): void
	{
		EventService::addEvent($type, $data);
	}
}

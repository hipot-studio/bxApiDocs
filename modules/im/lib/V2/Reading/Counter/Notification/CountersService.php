<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Notification;

use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;

class CountersService
{
	public function __construct(
		protected readonly CountersProvider $provider,
		protected readonly CounterOverflowService $overflowService,
	) {}

	public function getForUser(int $userId): int
	{
		return $this->getForUsers([$userId])->getByUserId($userId);
	}

	public function getForUsers(array $userIds): UsersCounterMap
	{
		$counters = $this->provider->getForUsers($userIds);
		$this->overflowService->insertNotificationOverflow($counters);

		return $counters;
	}
}

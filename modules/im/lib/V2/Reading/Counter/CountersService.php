<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter;

use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;

class CountersService
{
	public function __construct(
		protected readonly CountersProvider $provider,
		protected readonly CounterOverflowService $overflowService,
	) {}

	public function getForUsersWithOverflowTracking(int $chatId, array $userIds): UsersCounterMap
	{
		$counters = $this->provider->getForUsers($chatId, $userIds);
		$this->overflowService->insertOverflowed($counters, $chatId);

		return $counters;
	}
}

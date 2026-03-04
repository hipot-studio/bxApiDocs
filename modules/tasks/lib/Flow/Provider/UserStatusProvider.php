<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Provider;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Flow\Provider\Trait\CacheTrait;

class UserStatusProvider
{
	use CacheTrait;

	private const USER_STATUS_ACTIVE = 'active';
	private const USER_STATUS_EXISTS = 'exists';

	/** @var array<int, array{active: bool, exists: bool }> */
	private static array $cache = [];

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterExists(array $userIds): array
	{
		return $this->filterByStatus(
			$userIds,
			static fn(int $userId): bool => self::$cache[$userId][self::USER_STATUS_EXISTS],
		);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterActive(array $userIds): array
	{
		return $this->filterByStatus(
			$userIds,
			static fn(int $userId): bool => self::$cache[$userId][self::USER_STATUS_ACTIVE],
		);
	}

	public function exists(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$this->load($userId);

		return self::$cache[$userId][self::USER_STATUS_EXISTS];
	}

	public function isActive(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$this->load($userId);

		return self::$cache[$userId][self::USER_STATUS_ACTIVE];
	}

	private function filterByStatus(array $userIds, callable $callback): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);
		if (empty($userIds))
		{
			return [];
		}

		$this->load(...$userIds);

		return array_filter(
			$userIds,
			static fn(int $userId): bool => $callback($userId),
		);
	}

	private function load(int ...$userIds): void
	{
		$notLoaded = $this->getNotLoaded(...$userIds);
		if (empty($notLoaded))
		{
			return;
		}

		$userList = UserTable::query()
			->setSelect(['ID', 'ACTIVE'])
			->whereIn('ID', $notLoaded)
			->exec()
			->fetchAll();
		
		$existsIds = [];
		foreach ($userList as $user)
		{
			$userId = (int)$user['ID'];
			$existsIds[] = $userId;

			$this->store($userId, [
				self::USER_STATUS_EXISTS => true,
				self::USER_STATUS_ACTIVE => ($user['ACTIVE'] === 'Y'),
			]);
		}
		
		$notExistsIds = array_diff($notLoaded, $existsIds);
		foreach ($notExistsIds as $userId)
		{
			$this->store($userId, [
				self::USER_STATUS_EXISTS => false,
				self::USER_STATUS_ACTIVE => false,
			]);
		}
	}
}

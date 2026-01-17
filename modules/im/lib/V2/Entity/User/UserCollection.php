<?php

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Model\StatusTable;
use Bitrix\Im\V2\Entity\EntityCollection;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Search\Content;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;

/**
 * @implements \IteratorAggregate<int,User>
 * @method User offsetGet($offset)
 * @method User getById(int $id)
 */
class UserCollection extends EntityCollection
{
	public function __construct(array $usersIds = [])
	{
		parent::__construct();

		foreach ($usersIds as $userId)
		{
			$this[] = User::getInstance($userId);
		}
	}

	public function fillOnlineData(bool $withStatus = false): void
	{
		$idsUsersWithoutOnlineData = [];

		foreach ($this as $user)
		{
			if (!$user->isOnlineDataFilled($withStatus))
			{
				$idsUsersWithoutOnlineData[] = $user->getId();
			}
		}

		$idsUsersWithoutOnlineData = array_unique($idsUsersWithoutOnlineData);

		if (empty($idsUsersWithoutOnlineData))
		{
			return;
		}

		$select = $withStatus ? User::ONLINE_DATA_SELECTED_FIELDS : User::ONLINE_DATA_SELECTED_FIELDS_WITHOUT_STATUS;
		$query = UserTable::query()
			->setSelect($select)
			->whereIn('ID', $idsUsersWithoutOnlineData)
		;
		if ($withStatus)
		{
			$query->registerRuntimeField(
				new Reference(
					'STATUS',
					StatusTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_LEFT]
				)
			);
		}
		$statusesData = $query->fetchAll() ?: [];

		foreach ($statusesData as $statusData)
		{
			$this->getById((int)$statusData['USER_ID'])->setOnlineData($statusData, $withStatus);
		}
	}

	public static function findByName(string $searchString, array $excludeUserIds, int $limit): self
	{
		$matchString = self::prepareSearchString($searchString);

		if ($matchString === null)
		{
			return new static();
		}

		$usersQuery = UserTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				'USER_INDEX',
				new Reference(
					'USER_INDEX',
					UserIndexTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => 'INNER']
				)
			)
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', true)
			->whereNotIn('ID', $excludeUserIds)
			->whereMatch('USER_INDEX.SEARCH_USER_CONTENT', $matchString)
			->setLimit($limit);

		$userIds = array_map('intval', array_column($usersQuery->fetchAll(), 'ID'));

		return new static($userIds);
	}

	private static function prepareSearchString(string $query): ?string
	{
		$preparedString = Content::prepareStringToken($query);
		$matchString = Helper::matchAgainstWildcard($preparedString);

		if (!Content::canUseFulltextSearch($matchString))
		{
			return null;
		}

		return $matchString;
	}

	public static function filterUserIds(array $userIds, callable $predicate, ?int $limit = null): array
	{
		$filteredUserIds = [];
		foreach ($userIds as $userId)
		{
			if ($limit !== null && count($filteredUserIds) >= $limit)
			{
				return $filteredUserIds;
			}

			$user = User::getInstance((int)$userId);
			if ($predicate($user))
			{
				$filteredUserIds[(int)$userId] = (int)$userId;
			}
		}

		return $filteredUserIds;
	}

	public static function hasUserByType(array $userIds, UserType $type): bool
	{
		$filter = static fn (User $user) => $user->getType() === $type;
		$firstUserByType = static::filterUserIds($userIds, $filter, 1);

		return !empty($firstUserByType);
	}

	public function toRestFormat(array $option = []): array
	{
		if (!($option['WITHOUT_ONLINE'] ?? false))
		{
			$this->fillOnlineData();
		}

		return parent::toRestFormat($option);
	}

	public static function getRestEntityName(): string
	{
		return 'users';
	}

	/**
	 * Collect only existing users
	 * @param $offset
	 * @param $value
	 * @return void
	 */
	public function offsetSet($offset, $value): void
	{
		/** @var User $value */
		if (!$value->isExist())
		{
			return;
		}

		parent::offsetSet($offset, $value);
	}
}
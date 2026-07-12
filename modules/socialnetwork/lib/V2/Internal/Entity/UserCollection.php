<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

/**
 * @method null|User findOne(array $conditions)
 * @method null|User findOneById(int $id, string $idKey = 'id')
 * @method UserCollection findAll(array $conditions)
 * @method UserCollection findAllByIds(array $ids, string $idKey = 'id')
 * @method array getEmailList()
 * @method User[] getIterator()
 * @method static UserCollection mapFromIds(array $ids, string $idKey = 'id')
 * @method UserCollection filter(callable $callback)
 */
class UserCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return User::class;
	}

	/**
	 * @return array<int, User>
	 */
	public function indexById(): array
	{
		$usersById = [];

		foreach ($this as $user)
		{
			if ($user->id === null)
			{
				continue;
			}

			$usersById[$user->id] = $user;
		}

		return $usersById;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Relation\Provider;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\PositiveNumber;

class RelationCursor implements RestConvertible
{
	/**
	 * Pseudo-role used only by the cursor to address the "guest = collaber" group in grouped
	 * sort. It never collides with UserType::GUEST and never appears in the relation rest role.
	 */
	public const ROLE_GUEST_GROUP = 'GUEST_GROUP';

	/**
	 * Maps a cursor role (real role or the guest_group pseudo-role) to the ALG-01 group rank.
	 */
	public const RANK_BY_ROLE_MAP = [
		Chat::ROLE_OWNER => RelationProvider::GROUP_RANK_OWNER,
		Chat::ROLE_MANAGER => RelationProvider::GROUP_RANK_MANAGER,
		self::ROLE_GUEST_GROUP => RelationProvider::GROUP_RANK_GUEST,
		Chat::ROLE_MEMBER => RelationProvider::GROUP_RANK_MEMBER,
	];

	public function __construct(
		#[InArray([Chat::ROLE_MEMBER, Chat::ROLE_MANAGER, Chat::ROLE_OWNER, self::ROLE_GUEST_GROUP])]
		public readonly string $role,
		#[PositiveNumber]
		public readonly int $relationId,
	){}

	public static function getRestEntityName(): string
	{
		return 'nextCursor';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'role' => mb_strtolower($this->role),
			'relationId' => $this->relationId,
		];
	}

	public static function createFromArray(array $parameters): static
	{
		return new static(mb_strtoupper($parameters['role'] ?? ''), (int)($parameters['relationId'] ?? 0));
	}

	/**
	 * Group rank for grouped applyCursor. Unknown roles fall back to the "member" group so a
	 * guest_group cursor passed into the default (flag off) call never crashes the public REST.
	 */
	public static function rankByRole(string $role): int
	{
		return self::RANK_BY_ROLE_MAP[$role] ?? RelationProvider::GROUP_RANK_MEMBER;
	}

	public static function getNext(RelationCollection $relations, int $limit): ?static
	{
		if ($relations->count() < $limit)
		{
			return null;
		}

		$maxRole = null;
		$maxRolePriority = 0;
		$maxId = 0;

		foreach ($relations as $relation)
		{
			$rolePriority = RelationProvider::ROLE_PRIORITY_MAP[$relation->getRole()];
			if ($rolePriority > $maxRolePriority)
			{
				$maxRole = $relation->getRole();
				$maxRolePriority = $rolePriority;
				$maxId = $relation->getId();
			}
			if ($relation->getId() > $maxId)
			{
				$maxId = $relation->getId();
			}
		}

		if ($maxRole === null || $maxId === null)
		{
			return null;
		}

		return new RelationCursor($maxRole, $maxId);
	}
}

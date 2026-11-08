<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

class MemberEntityMapper
{
	private const ACCESS_CODE_USER = 'U';
	private const ACCESS_CODE_DEPARTMENT = 'DR';
	private const ACCESS_CODE_DEPARTMENT_FLAT = 'D';
	private const ACCESS_CODE_TEAM = 'SNT';
	private const ACCESS_CODE_TEAM_RECURSIVE = 'SNTR';

	/**
	 * Maps member type and node scope to an access code prefix, in both directions. Ordered by prefix length
	 * descending, so that a recursive prefix is always tried before the flat variant it contains: SNTR before
	 * SNT, DR before D.
	 *
	 * @var list<array{prefix: string, type: MemberEntityType, withChildNodes: ?bool}>
	 */
	private const ACCESS_CODE_PREFIXES = [
		[
			'prefix' => self::ACCESS_CODE_TEAM_RECURSIVE,
			'type' => MemberEntityType::Team,
			'withChildNodes' => true,
		],
		[
			'prefix' => self::ACCESS_CODE_TEAM,
			'type' => MemberEntityType::Team,
			'withChildNodes' => false,
		],
		[
			'prefix' => self::ACCESS_CODE_DEPARTMENT,
			'type' => MemberEntityType::Department,
			'withChildNodes' => true,
		],
		[
			'prefix' => self::ACCESS_CODE_USER,
			'type' => MemberEntityType::User,
			'withChildNodes' => null,
		],
		[
			'prefix' => self::ACCESS_CODE_DEPARTMENT_FLAT,
			'type' => MemberEntityType::Department,
			'withChildNodes' => false,
		],
	];

	public function fromArray(array $member): ?MemberEntity
	{
		$id = isset($member['id']) && is_numeric($member['id'])
			? (int)$member['id']
			: 0
		;
		$type =
			is_string($member['type'] ?? null)
				? MemberEntityType::tryFrom($member['type'])
				: null
		;

		if ($id <= 0 || $type === null)
		{
			return null;
		}

		$withChildNodes = null;
		if ($type !== MemberEntityType::User)
		{
			$withChildNodes = is_bool($member['withChildNodes'] ?? null)
				? $member['withChildNodes']
				: true
			;
		}

		return new MemberEntity(
			id: $id,
			type: $type,
			withChildNodes: $withChildNodes,
		);
	}

	public function fromArrays(array $members): MemberEntityCollection
	{
		$collection = new MemberEntityCollection();
		foreach ($members as $member)
		{
			if (!is_array($member))
			{
				continue;
			}

			$entity = $this->fromArray($member);
			if ($entity !== null)
			{
				$collection->add($entity);
			}
		}

		return $collection;
	}

	public function fromUserIds(array $userIds): MemberEntityCollection
	{
		$collection = new MemberEntityCollection();
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$collection->add(new MemberEntity(id: $userId, type: MemberEntityType::User));
			}
		}

		return $collection;
	}

	public function toAccessCode(MemberEntity $entity): ?string
	{
		if ($entity->id === null || $entity->type === null)
		{
			return null;
		}

		return $this->buildAccessCode($entity->type, $entity->withChildNodes, $entity->id);
	}

	public function toUserAccessCode(int $userId): ?string
	{
		return $this->buildAccessCode(MemberEntityType::User, null, $userId);
	}

	private function buildAccessCode(MemberEntityType $type, ?bool $withChildNodes, int $id): ?string
	{
		if ($id <= 0)
		{
			return null;
		}

		$scope = $type === MemberEntityType::User ? null : $withChildNodes;

		foreach (self::ACCESS_CODE_PREFIXES as $prefix)
		{
			if ($prefix['type'] === $type && $prefix['withChildNodes'] === $scope)
			{
				return $prefix['prefix'] . $id;
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	public function toAccessCodes(MemberEntityCollection $collection): array
	{
		$codes = [];
		foreach ($collection as $entity)
		{
			$code = $this->toAccessCode($entity);
			if ($code !== null)
			{
				$codes[] = $code;
			}
		}

		return $codes;
	}

	public function fromAccessCode(string $code): ?MemberEntity
	{
		foreach (self::ACCESS_CODE_PREFIXES as $prefix)
		{
			$id = $this->extractAccessCodeId($code, $prefix['prefix']);
			if ($id === null)
			{
				continue;
			}

			return new MemberEntity(
				id: $id,
				type: $prefix['type'],
				withChildNodes: $prefix['withChildNodes'],
			);
		}

		return null;
	}

	private function extractAccessCodeId(string $code, string $prefix): ?int
	{
		if (!str_starts_with($code, $prefix))
		{
			return null;
		}

		$id = substr($code, strlen($prefix));

		return preg_match('/^[0-9]+$/D', $id) === 1 && (int)$id > 0 ? (int)$id : null;
	}

	public function fromAccessCodes(array $codes): MemberEntityCollection
	{
		$collection = new MemberEntityCollection();
		foreach ($codes as $code)
		{
			$entity = $this->fromAccessCode($code);
			if ($entity !== null)
			{
				$collection->add($entity);
			}
		}

		return $collection;
	}
}

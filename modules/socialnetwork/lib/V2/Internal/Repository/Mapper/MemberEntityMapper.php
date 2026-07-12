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
		if ($type === MemberEntityType::Department)
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

		return match ($entity->type)
		{
			MemberEntityType::User => $this->toUserAccessCode($entity->id),
			MemberEntityType::Department => is_bool($entity->withChildNodes)
				? ($entity->withChildNodes ? self::ACCESS_CODE_DEPARTMENT : self::ACCESS_CODE_DEPARTMENT_FLAT) . $entity->id
				: null,
		};
	}

	public function toUserAccessCode(int $userId): ?string
	{
		return $userId > 0
			? self::ACCESS_CODE_USER . $userId
			: null
		;
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
		if (str_starts_with($code, self::ACCESS_CODE_DEPARTMENT))
		{
			$id = (int)substr($code, strlen(self::ACCESS_CODE_DEPARTMENT));
			if ($id > 0)
			{
				return new MemberEntity(
					id: $id,
					type: MemberEntityType::Department,
					withChildNodes: true,
				);
			}
		}

		if (str_starts_with($code, self::ACCESS_CODE_DEPARTMENT_FLAT))
		{
			$id = (int)substr($code, strlen(self::ACCESS_CODE_DEPARTMENT_FLAT));
			if ($id > 0)
			{
				return new MemberEntity(
					id: $id,
					type: MemberEntityType::Department,
					withChildNodes: false,
				);
			}
		}

		if (str_starts_with($code, self::ACCESS_CODE_USER))
		{
			$id = (int)substr($code, strlen(self::ACCESS_CODE_USER));
			if ($id > 0)
			{
				return new MemberEntity(id: $id, type: MemberEntityType::User);
			}
		}

		return null;
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

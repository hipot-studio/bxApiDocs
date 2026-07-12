<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\Member;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberCollection;

class ProjectMemberMapper
{
	private readonly MemberEntityMapper $memberEntityMapper;

	public function __construct()
	{
		$this->memberEntityMapper = new MemberEntityMapper();
	}

	public function mapRequestCollection(mixed $members): ?MemberCollection
	{
		if (!is_array($members))
		{
			return null;
		}

		if ($members === [])
		{
			return new MemberCollection();
		}

		$entityMembers = $this->memberEntityMapper->fromArrays($members);
		if ($entityMembers->isEmpty())
		{
			return null;
		}

		return $this->mapToDtoCollection($entityMembers);
	}

	public function mapToEntityCollection(?MemberCollection $members): ?MemberEntityCollection
	{
		if ($members === null)
		{
			return null;
		}

		if ($members->isEmpty())
		{
			return new MemberEntityCollection();
		}

		$entities = [];
		foreach ($members as $member)
		{
			if (!$member instanceof Member)
			{
				continue;
			}

			$entity = $this->memberEntityMapper->fromArray($member->toArray());
			if ($entity !== null)
			{
				$entities[] = $entity;
			}
		}

		if ($entities === [])
		{
			return null;
		}

		return new MemberEntityCollection(...$entities);
	}

	public function mapToDtoCollection(?MemberEntityCollection $members): ?MemberCollection
	{
		if ($members === null)
		{
			return null;
		}

		$items = [];
		foreach ($members as $member)
		{
			if (!$member instanceof MemberEntity || $member->id === null || $member->type === null)
			{
				continue;
			}

			$items[] = new Member(
				id: $member->id,
				type: $member->type,
				withChildNodes: $member->type === MemberEntityType::Department
					? $member->withChildNodes
					: null,
			);
		}

		return new MemberCollection(...$items);
	}
}

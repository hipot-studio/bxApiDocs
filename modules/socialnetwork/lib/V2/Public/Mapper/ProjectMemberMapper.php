<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Integration\UI\EntitySelector\Provider\DepartmentSelectorItemProvider;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\Member;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberCollection;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\MemberResponse;

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
			if (!$member instanceof MemberEntity || ($member->id ?? 0) <= 0 || $member->type === null)
			{
				continue;
			}

			$items[] = new Member(
				id: $member->id,
				type: $member->type,
				// Structure nodes (departments and teams) carry a scope, a user never does
				withChildNodes: $member->type === MemberEntityType::User
					? null
					: $member->withChildNodes,
			);
		}

		return new MemberCollection(...$items);
	}

	public function mapToResponseArray(?MemberEntityCollection $members, array $selectorItems = []): ?array
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

			$selectorItem = $this->getSelectorItem($member, $selectorItems);
			$name = $member->type === MemberEntityType::Department && $selectorItem === null
				? null
				: $member->name
			;

			$items[] = (new MemberResponse(
				id: $member->id,
				type: $member->type,
				withChildNodes: $member->withChildNodes,
				name: $name,
				image: $member->image,
				selectorItem: $selectorItem,
			))->toArray();
		}

		return $items;
	}

	private function getSelectorItem(MemberEntity $member, array $selectorItems): ?array
	{
		if ($member->type !== MemberEntityType::Department || ($member->id ?? 0) <= 0)
		{
			return null;
		}

		$selectorId = $member->withChildNodes === false ? "{$member->id}:F" : $member->id;
		$item = $selectorItems[DepartmentSelectorItemProvider::getItemKey($selectorId)] ?? null;

		return is_array($item) ? $item : null;
	}
}

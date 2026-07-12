<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use CSocNetAllowed;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

class ProjectInputNormalizer
{
	/**
	 * Normalizes members/moderatorMembers input:
	 * - keeps only supported entity types for each field
	 * - deduplicates by entity id with "last wins"
	 * - removes moderator users from regular members
	 */
	public function normalizeMembers(
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): ?MemberEntityCollection
	{
		[$normalizedMembers] = $this->normalizeMemberCollections($members, $moderatorMembers);

		return $normalizedMembers;
	}

	/**
	 * @return array{0: ?MemberEntityCollection, 1: ?MemberEntityCollection}
	 */
	public function normalizeMemberCollections(
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): array
	{
		$normalized = $this->normalizeMemberCollectionsForDiff($members, $moderatorMembers);

		return [
			$normalized['members']['collection'],
			$normalized['moderatorMembers']['collection'],
		];
	}

	/**
	 * @return array{0: ?MemberEntityCollection, 1: ?MemberEntityCollection}
	 */
	public function normalizeUpdateMemberCollections(
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): array
	{
		$normalizedMembers = $this->normalizeUpdateCollection(
			$members,
			[MemberEntityType::User, MemberEntityType::Department],
		);
		$normalizedModeratorMembers = $this->normalizeUpdateCollection(
			$moderatorMembers,
			[MemberEntityType::User],
		);

		return [
			$this->excludeModeratorMembers($normalizedMembers, $normalizedModeratorMembers),
			$normalizedModeratorMembers,
		];
	}

	/**
	 * @return array{
	 *  members: array{collection: ?MemberEntityCollection, hadSupportedEntries: bool},
	 *  moderatorMembers: array{collection: ?MemberEntityCollection, hadSupportedEntries: bool}
	 * }
	 */
	public function normalizeMemberCollectionsForDiff(
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): array
	{
		$normalizedMembersBeforeModeratorFilter = $this->normalizeCollection(
			$members,
			[MemberEntityType::User, MemberEntityType::Department],
		);
		$normalizedModeratorMembers = $this->normalizeCollection(
			$moderatorMembers,
			[MemberEntityType::User],
		);

		return [
			'members' => [
				'collection' => $this->excludeModeratorMembers(
					$normalizedMembersBeforeModeratorFilter,
					$normalizedModeratorMembers,
				),
				'hadSupportedEntries' => $normalizedMembersBeforeModeratorFilter !== null
					&& !$normalizedMembersBeforeModeratorFilter->isEmpty(),
			],
			'moderatorMembers' => [
				'collection' => $normalizedModeratorMembers,
				'hadSupportedEntries' => $normalizedModeratorMembers !== null
					&& !$normalizedModeratorMembers->isEmpty(),
			],
		];
	}

	public function normalizePermissions(?array $permissions): ?array
	{
		if ($permissions === null)
		{
			return null;
		}

		$allowed = $this->getAllowedFeaturesMap();
		$normalized = [];

		foreach ($permissions as $featureName => $operations)
		{
			if (!isset($allowed[$featureName]) || !is_array($operations))
			{
				continue;
			}

			$allowedOperations = $allowed[$featureName]['operations'] ?? [];
			$filteredOperations = array_intersect_key($operations, $allowedOperations);

			if (!empty($filteredOperations))
			{
				$normalized[$featureName] = $filteredOperations;
			}
		}

		return $normalized !== [] ? $normalized : null;
	}

	/**
	 * @return array{0: ?DateTime, 1: ?DateTime}
	 */
	public function normalizeDateRange(
		?DateTime $dateStart,
		?DateTime $dateFinish,
	): array
	{
		if (
			$dateStart !== null
			&& $dateFinish !== null
			&& $dateFinish->getTimestamp() < $dateStart->getTimestamp()
		)
		{
			$dateFinish = null;
		}

		return [$dateStart, $dateFinish];
	}

	protected function getAllowedFeaturesMap(): array
	{
		return CSocNetAllowed::getAllowedFeatures();
	}

	/**
	 * @param MemberEntityType[] $allowedTypes
	 */
	private function normalizeCollection(
		?MemberEntityCollection $members,
		array $allowedTypes,
	): ?MemberEntityCollection
	{
		if ($members === null)
		{
			return null;
		}

		$normalizedMembers = [];
		foreach ($members as $member)
		{
			if (!$member instanceof MemberEntity)
			{
				continue;
			}

			$normalizedMember = $this->normalizeMember($member, $allowedTypes);
			if ($normalizedMember === null)
			{
				continue;
			}

			$key = $this->buildDedupKey($normalizedMember);
			if ($key === null)
			{
				continue;
			}

			unset($normalizedMembers[$key]);
			$normalizedMembers[$key] = $normalizedMember;
		}

		return new MemberEntityCollection(...array_values($normalizedMembers));
	}

	/**
	 * @param MemberEntityType[] $allowedTypes
	 */
	private function normalizeMember(MemberEntity $member, array $allowedTypes): ?MemberEntity
	{
		if (
			$member->id === null
			|| $member->id <= 0
			|| $member->type === null
			|| !in_array($member->type, $allowedTypes, true)
		)
		{
			return null;
		}

		return new MemberEntity(
			id: $member->id,
			type: $member->type,
			withChildNodes: $member->type === MemberEntityType::Department
				? $member->withChildNodes !== false
				: null,
			name: $member->name,
			image: $member->image,
		);
	}

	/**
	 * @param MemberEntityType[] $allowedTypes
	 */
	private function normalizeUpdateCollection(
		?MemberEntityCollection $members,
		array $allowedTypes,
	): ?MemberEntityCollection
	{
		if ($members === null)
		{
			return null;
		}

		if ($members->isEmpty())
		{
			return new MemberEntityCollection();
		}

		$normalizedMembers = $this->normalizeCollection($members, $allowedTypes);

		return $normalizedMembers !== null && !$normalizedMembers->isEmpty()
			? $normalizedMembers
			: null
		;
	}

	private function excludeModeratorMembers(
		?MemberEntityCollection $members,
		?MemberEntityCollection $moderatorMembers,
	): ?MemberEntityCollection
	{
		if (
			$members === null
			|| $moderatorMembers === null
			|| $moderatorMembers->isEmpty()
		)
		{
			return $members;
		}

		$moderatorKeys = [];
		foreach ($moderatorMembers as $moderator)
		{
			$key = $this->buildDedupKey($moderator);
			if ($key !== null)
			{
				$moderatorKeys[$key] = true;
			}
		}

		$filteredMembers = [];
		foreach ($members as $member)
		{
			$key = $this->buildDedupKey($member);
			if ($key === null || !isset($moderatorKeys[$key]))
			{
				$filteredMembers[] = $member;
			}
		}

		return new MemberEntityCollection(...$filteredMembers);
	}

	private function buildDedupKey(MemberEntity $member): ?string
	{
		if ($member->id === null || $member->type === null)
		{
			return null;
		}

		return match ($member->type)
		{
			MemberEntityType::User => 'user:' . $member->id,
			MemberEntityType::Department => 'department:' . $member->id,
		};
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;

class ProjectMemberDiffBuilder
{
	private array $payloadCache = [];

	public function __construct(
		private readonly ProjectMemberRepositoryInterface $projectMemberRepository,
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly ProjectInputNormalizer $projectInputNormalizer,
	)
	{
	}

	public function build(int $projectId, array $projectData): array
	{
		$membersWasProvided = $this->isMemberFieldProvided($projectData, 'members');
		$membersWasExplicitlyEmpty = $membersWasProvided && $projectData['members'] === [];
		$moderatorsWasProvided = $this->isMemberFieldProvided($projectData, 'moderatorMembers');
		$moderatorsWasExplicitlyEmpty = $moderatorsWasProvided && $projectData['moderatorMembers'] === [];
		$normalizedCollections = $this->extractNormalizedMemberCollectionsForDiff($projectData);
		$membersMeta = $normalizedCollections['members'];
		$moderatorsMeta = $normalizedCollections['moderatorMembers'];
		$cacheKey = $this->getCacheKey(
			$projectId,
			$projectData,
			$membersMeta,
			$moderatorsMeta,
			$membersWasProvided,
			$membersWasExplicitlyEmpty,
			$moderatorsWasProvided,
			$moderatorsWasExplicitlyEmpty,
		);
		if (array_key_exists($cacheKey, $this->payloadCache))
		{
			return $this->payloadCache[$cacheKey];
		}

		$payload = [];
		$shouldBuildMembersDiff = $membersWasProvided
			&& ($membersWasExplicitlyEmpty || $membersMeta['hadSupportedEntries']);
		$shouldBuildModeratorDiff = $moderatorsWasProvided
			&& ($moderatorsWasExplicitlyEmpty || $moderatorsMeta['hadSupportedEntries']);

		$desiredModeratorCodes = $moderatorsMeta['collection'] === null
			? null
			: $this->memberEntityMapper->toAccessCodes($moderatorsMeta['collection']);

		if ($shouldBuildMembersDiff)
		{
			$desiredMemberCodes = $membersMeta['collection'] === null
				? []
				: $this->memberEntityMapper->toAccessCodes($membersMeta['collection']);
			// Moderators are removed from the raw members input during normalization,
			// but they still must stay in the final membership diff because moderator implies membership.
			$desiredMemberCodes = $this->mergeDesiredMembershipCodes($desiredMemberCodes, $desiredModeratorCodes);
			$currentCodes = $this->projectMemberRepository->getMemberCodes($projectId);
			[$desiredMemberCodes, $currentCodes] = $this->excludeOwnerFromMemberDiff(
				$projectId,
				$this->extractOwnerId($projectData),
				$desiredMemberCodes,
				$currentCodes,
			);

			$payload['addMembers'] = array_values(array_diff($desiredMemberCodes, $currentCodes));
			$payload['deleteMembers'] = array_values(array_diff($currentCodes, $desiredMemberCodes));
		}

		if ($shouldBuildModeratorDiff)
		{
			$currentModeratorCodes = $this->projectMemberRepository->getModeratorCodes($projectId);

			$payload['addModeratorMembers'] = array_values(
				array_diff($desiredModeratorCodes, $currentModeratorCodes),
			);
			$payload['deleteModeratorMembers'] = array_values(
				array_diff($currentModeratorCodes, $desiredModeratorCodes),
			);
		}

		$this->payloadCache[$cacheKey] = $payload;

		return $payload;
	}

	/**
	 * @return array{
	 *  members: array{collection: ?MemberEntityCollection, hadSupportedEntries: bool},
	 *  moderatorMembers: array{collection: ?MemberEntityCollection, hadSupportedEntries: bool}
	 * }
	 */
	private function extractNormalizedMemberCollectionsForDiff(array $projectData): array
	{
		return $this->projectInputNormalizer->normalizeMemberCollectionsForDiff(
			$this->extractMemberCollection($projectData, 'members'),
			$this->extractMemberCollection($projectData, 'moderatorMembers'),
		);
	}

	private function isMemberFieldProvided(array $projectData, string $field): bool
	{
		return array_key_exists($field, $projectData) && is_array($projectData[$field]);
	}

	private function extractMemberCollection(array $projectData, string $field): ?MemberEntityCollection
	{
		if (!$this->isMemberFieldProvided($projectData, $field))
		{
			return null;
		}

		return $this->memberEntityMapper->fromArrays($projectData[$field]);
	}

	private function extractOwnerId(array $projectData): ?int
	{
		$ownerId = $projectData['ownerId'] ?? null;

		return is_numeric($ownerId) ? (int)$ownerId : null;
	}

	/**
	 * @param string[] $desiredCodes
	 * @param string[] $currentCodes
	 * @return array{0: string[], 1: string[]}
	 */
	private function excludeOwnerFromMemberDiff(
		int $projectId,
		?int $newOwnerId,
		array $desiredCodes,
		array $currentCodes,
	): array
	{
		$currentOwnerId = $this->projectMemberRepository->getOwnerUserId($projectId);
		$ownersToIgnore = [];
		if ($currentOwnerId !== null && $currentOwnerId > 0)
		{
			$ownersToIgnore[] = $currentOwnerId;
		}
		if ($newOwnerId !== null && $newOwnerId > 0 && $newOwnerId !== $currentOwnerId)
		{
			$ownersToIgnore[] = $newOwnerId;
		}

		$ignoredOwnerCodes = array_values(array_filter(array_map(
			fn(int $ownerId): ?string => $this->memberEntityMapper->toUserAccessCode($ownerId),
			$ownersToIgnore,
		)));

		if ($ignoredOwnerCodes === [])
		{
			return [$desiredCodes, $currentCodes];
		}

		return [
			array_values(array_diff($desiredCodes, $ignoredOwnerCodes)),
			array_values(array_diff($currentCodes, $ignoredOwnerCodes)),
		];
	}

	/**
	 * @param string[] $desiredMemberCodes
	 * @param string[]|null $desiredModeratorCodes
	 * @return string[]
	 */
	private function mergeDesiredMembershipCodes(array $desiredMemberCodes, ?array $desiredModeratorCodes): array
	{
		if (empty($desiredModeratorCodes))
		{
			return $desiredMemberCodes;
		}

		return array_values(array_unique(array_merge($desiredMemberCodes, $desiredModeratorCodes)));
	}

	private function getCacheKey(
		int $projectId,
		array $projectData,
		array $membersMeta,
		array $moderatorsMeta,
		bool $membersWasProvided,
		bool $membersWasExplicitlyEmpty,
		bool $moderatorsWasProvided,
		bool $moderatorsWasExplicitlyEmpty,
	): string
	{
		return serialize([
			'projectId' => $projectId,
			'ownerId' => $projectData['ownerId'] ?? null,
			'membersWasProvided' => $membersWasProvided,
			'membersWasExplicitlyEmpty' => $membersWasExplicitlyEmpty,
			'membersHadSupportedEntries' => $membersMeta['hadSupportedEntries'],
			'members' => $this->extractCacheMemberCodes($membersMeta['collection']),
			'moderatorsWasProvided' => $moderatorsWasProvided,
			'moderatorsWasExplicitlyEmpty' => $moderatorsWasExplicitlyEmpty,
			'moderatorsHadSupportedEntries' => $moderatorsMeta['hadSupportedEntries'],
			'moderatorMembers' => $this->extractCacheMemberCodes($moderatorsMeta['collection']),
		]);
	}

	/**
	 * @return string[]|null
	 */
	private function extractCacheMemberCodes(?MemberEntityCollection $members): ?array
	{
		if ($members === null)
		{
			return null;
		}

		$codes = $this->memberEntityMapper->toAccessCodes($members);

		sort($codes);

		return $codes;
	}
}

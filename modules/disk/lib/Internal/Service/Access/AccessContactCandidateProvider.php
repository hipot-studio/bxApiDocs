<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;

final class AccessContactCandidateProvider implements AccessContactCandidateProviderInterface
{
	private const FAST_CANDIDATE_LIMIT = 5;
	private const EXTENDED_CANDIDATE_LIMIT = 50;

	public function __construct(
		private readonly AccessContactCandidateSourceInterface $source = new AccessContactCandidateSource(),
	)
	{
	}

	public function getFastCandidateIds(BaseObject $object): array
	{
		return $this->collectFastCandidateIds($object, self::FAST_CANDIDATE_LIMIT);
	}

	public function getExtendedCandidateIds(BaseObject $object): array
	{
		$candidateIds = $this->collectFastCandidateIds($object, self::FAST_CANDIDATE_LIMIT);

		$fullAccessUserIds = [];
		foreach ($this->source->getFullAccessRights($object) as $right)
		{
			if ((int)($right['NEGATIVE'] ?? 0) === 1)
			{
				continue;
			}

			// Only personal access codes name a user; broad and group codes are skipped.
			$userId = $this->getUserIdFromAccessCode((string)($right['ACCESS_CODE'] ?? ''));
			if ($userId !== null)
			{
				$fullAccessUserIds[$userId] = $userId;
			}
		}
		sort($fullAccessUserIds, SORT_NUMERIC);

		foreach ($fullAccessUserIds as $userId)
		{
			if (count($candidateIds) >= self::EXTENDED_CANDIDATE_LIMIT)
			{
				break;
			}

			$this->appendCandidate($candidateIds, $userId);
		}

		return $candidateIds;
	}

	/** @return int[] */
	private function collectFastCandidateIds(BaseObject $object, int $limit): array
	{
		$candidateIds = [];
		$this->appendCandidate($candidateIds, (int)$this->source->getPersonalStorageOwnerId($object));
		$this->appendCandidate($candidateIds, (int)$this->source->getAuthorId($object));

		$groupId = (int)$this->source->getGroupId($object);
		if ($groupId > 0 && count($candidateIds) < $limit)
		{
			$this->appendCandidate($candidateIds, (int)$this->source->getGroupOwnerId($groupId));
		}

		if ($groupId > 0 && count($candidateIds) < $limit)
		{
			// Moderators may repeat the ones taken above, so the whole limit is asked for and duplicates are dropped.
			// The eligibility checker still requires an actual right to change rights.
			foreach ($this->source->getGroupModeratorIds($groupId, $limit) as $moderatorId)
			{
				if (count($candidateIds) >= $limit)
				{
					break;
				}

				$this->appendCandidate($candidateIds, (int)$moderatorId);
			}
		}

		return array_slice($candidateIds, 0, $limit);
	}

	private function getUserIdFromAccessCode(string $accessCode): ?int
	{
		if (preg_match('/^(?:U|IU)(\d+)$/', $accessCode, $matches) !== 1)
		{
			return null;
		}

		$userId = (int)$matches[1];

		return $userId > 0 ? $userId : null;
	}

	/** @param int[] $candidateIds */
	private function appendCandidate(array &$candidateIds, int $candidateId): void
	{
		if ($candidateId > 0 && !in_array($candidateId, $candidateIds, true))
		{
			$candidateIds[] = $candidateId;
		}
	}
}

<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;

final class AccessContactResolver
{
	public function __construct(
		private readonly AccessContactCandidateProviderInterface $candidateProvider,
		private readonly AccessContactEligibilityCheckerInterface $eligibilityChecker,
	)
	{
	}

	/** @return array{id: int, name: string, avatar: string}|null */
	public function resolveFast(BaseObject $object, int $currentUserId): ?array
	{
		return $this->resolve($object, $currentUserId, $this->candidateProvider->getFastCandidateIds($object));
	}

	/** @return array{id: int, name: string, avatar: string}|null */
	public function resolveExtended(BaseObject $object, int $currentUserId): ?array
	{
		return $this->resolve($object, $currentUserId, $this->candidateProvider->getExtendedCandidateIds($object));
	}

	/**
	 * @param int[] $candidateIds
	 * @return array{id: int, name: string, avatar: string}|null
	 */
	private function resolve(BaseObject $object, int $currentUserId, array $candidateIds): ?array
	{
		foreach ($candidateIds as $candidateId)
		{
			$contact = $this->eligibilityChecker->getEligibleContact(
				$object,
				(int)$candidateId,
				$currentUserId,
			);
			if ($contact !== null)
			{
				return $contact;
			}
		}

		return null;
	}
}

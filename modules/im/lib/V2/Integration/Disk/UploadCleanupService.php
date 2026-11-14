<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Disk;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UploadCleanup\CleanupGuard;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;

final class UploadCleanupService
{
	public function hasMarker(int $objectId): bool
	{
		if (!DiskCapability::isUploadLimitAvailable())
		{
			return false;
		}

		return (new MarkerService())->hasMarker($objectId);
	}

	public function runWithConfirmedRevokedMarker(int $objectId, callable $operation): mixed
	{
		if (!DiskCapability::isUploadLimitAvailable())
		{
			return $operation();
		}

		return (new MarkerService())->runWithConfirmedRevokedMarker($objectId, $operation);
	}

	/**
	 * @param array<int, array{userId: int, parentId: int}> $identities
	 * @return array{confirmedRevokedIds: int[], publicationClaims: array<int, string>}|null
	 */
	public function reserveBatchBeforeUse(array $identities, bool $forPublication): ?array
	{
		$confirmedRevokedIds = [];
		$publicationClaims = [];
		if (DiskCapability::isUploadLimitAvailable())
		{
			$markerService = new MarkerService();
			$reserved = $forPublication
				? $markerService->claimBatchBeforePublication($identities, $confirmedRevokedIds, $publicationClaims)
				: $markerService->revokeBatchBeforeUse($identities, $confirmedRevokedIds);
			if (!$reserved)
			{
				return null;
			}
		}

		return [
			'confirmedRevokedIds' => $confirmedRevokedIds,
			'publicationClaims' => $publicationClaims,
		];
	}

	/**
	 * @param array<int, array{file: File, claimToken: string, expectedGlobalContentVersion: int}> $claims
	 */
	public function commitPublicationClaims(array $claims): void
	{
		if ($claims === [] || !DiskCapability::isUploadLimitAvailable())
		{
			return;
		}

		$markerService = new MarkerService();
		$claimTokens = array_map(
			static fn(array $claim): string => $claim['claimToken'],
			$claims,
		);
		if (!$markerService->commitPublicationClaims($claimTokens))
		{
			foreach ($claimTokens as $objectId => $claimToken)
			{
				$markerService->commitPublicationClaims([$objectId => $claimToken]);
			}
		}
	}

	/**
	 * @param array<int, array{file: File, claimToken: string, expectedGlobalContentVersion: int}> $claims
	 */
	public function rollbackPublicationClaims(array $claims, int $userId): void
	{
		if ($claims === [] || !DiskCapability::isUploadLimitAvailable())
		{
			return;
		}

		$markerService = new MarkerService();
		$files = [];
		$parentIds = [];
		$expectedVersions = [];
		foreach ($claims as $objectId => $claim)
		{
			$objectId = (int)$objectId;
			if (!$markerService->claimPublicationRollback($objectId, $claim['claimToken']))
			{
				continue;
			}

			$file = File::loadById($objectId, ['STORAGE']);
			if (!$file)
			{
				$markerService->consumeClaim($objectId, $claim['claimToken']);

				continue;
			}
			$files[$objectId] = $file;
			$parentIds[$objectId] = (int)$claim['file']->getParentId();
			$expectedVersions[$objectId] = $claim['expectedGlobalContentVersion'];
		}

		$guardFailures = (new CleanupGuard())->checkBatch(
			$files,
			$userId,
			$parentIds,
			$expectedVersions,
		);
		foreach ($files as $objectId => $file)
		{
			$claimToken = $claims[$objectId]['claimToken'];
			$guardFailure = array_key_exists($objectId, $guardFailures)
				? $guardFailures[$objectId]
				: CleanupGuard::IN_USE;
			if ($guardFailure !== null)
			{
				$markerService->revokeClaim($objectId, $claimToken);

				continue;
			}

			$deleted = $file->delete($userId);
			if ($deleted)
			{
				$markerService->consumeClaim($objectId, $claimToken);
			}
			else
			{
				$markerService->revokeClaim($objectId, $claimToken);
			}
		}
	}
}

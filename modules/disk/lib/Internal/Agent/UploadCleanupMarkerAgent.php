<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Agent;

use Bitrix\Disk\File;
use Bitrix\Disk\Infrastructure\Agent\BaseDiskAgent;
use Bitrix\Disk\Infrastructure\Agent\RunResult;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\UploadCleanup\CleanupGuard;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerStatus;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\UploadCleanupMarkerTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

final class UploadCleanupMarkerAgent extends BaseDiskAgent
{
	private const BATCH_SIZE = 200;
	private const RUN_TIME_LIMIT_SECONDS = 20;

	private readonly MarkerService $markerService;
	private readonly CleanupGuard $guard;
	private readonly LoggerInterface $logger;

	public function __construct()
	{
		$this->markerService = new MarkerService();
		$this->guard = new CleanupGuard();
		$this->logger = LoggerFactory::create(feature: 'upload cleanup marker');
	}

	protected function runInternal(): RunResult
	{
		$result = new RunResult(true);
		if (!$this->markerService->isAvailable())
		{
			return $result;
		}

		$deadline = microtime(true) + self::RUN_TIME_LIMIT_SECONDS;
		$recoveryCursor = null;
		$recoveryFinished = false;
		$terminalCursors = [
			MarkerStatus::Revoked->value => null,
			MarkerStatus::Consumed->value => null,
		];
		$terminalFinished = [
			MarkerStatus::Revoked->value => false,
			MarkerStatus::Consumed->value => false,
		];
		do
		{
			$progress = $this->markerService->revokeExpiredPending(self::BATCH_SIZE);
			$progress += $this->markerService->queueStalePublicationsForRecovery(self::BATCH_SIZE);
			if (microtime(true) >= $deadline)
			{
				break;
			}

			if (!$recoveryFinished)
			{
				$recovered = $this->recoverStaleClaimsBatch($recoveryCursor, $deadline);
				$recoveryFinished = $recovered === 0;
				$progress += $recovered;
			}

			foreach ([MarkerStatus::Revoked, MarkerStatus::Consumed] as $status)
			{
				if (microtime(true) >= $deadline || $terminalFinished[$status->value])
				{
					continue;
				}
				$removed = $this->removeOldTerminalRowsBatch(
					$status,
					$terminalCursors[$status->value],
				);
				$terminalFinished[$status->value] = $removed === 0;
				$progress += $removed;
			}
		}
		while ($progress > 0 && microtime(true) < $deadline);

		return $result;
	}

	/**
	 * @param array{claimedAt: DateTime, objectId: int}|null $cursor
	 */
	private function recoverStaleClaimsBatch(?array &$cursor, float $deadline): int
	{
		$staleBefore = DateTime::createFromTimestamp(time() - MarkerService::CLAIM_LEASE_SECONDS);
		$filter = [
			'@STATUS' => [MarkerStatus::Deleting->value, MarkerStatus::PublicationDeleting->value],
			'<CLAIMED_AT' => $staleBefore,
		];
		if ($cursor !== null)
		{
			$filter[] = [
				'LOGIC' => 'OR',
				['>CLAIMED_AT' => $cursor['claimedAt']],
				[
					'=CLAIMED_AT' => $cursor['claimedAt'],
					'>OBJECT_ID' => $cursor['objectId'],
				],
			];
		}
		$rows = UploadCleanupMarkerTable::getList([
			'select' => ['OBJECT_ID', 'USER_ID', 'PARENT_ID', 'STATUS', 'CLAIM_TOKEN', 'CLAIMED_AT'],
			'filter' => $filter,
			'order' => ['CLAIMED_AT' => 'ASC', 'OBJECT_ID' => 'ASC'],
			'limit' => self::BATCH_SIZE,
		])->fetchAll();

		$processed = 0;
		$recoveredClaims = [];
		foreach ($rows as $row)
		{
			if (microtime(true) >= $deadline)
			{
				break;
			}
			$cursor = [
				'claimedAt' => $row['CLAIMED_AT'],
				'objectId' => (int)$row['OBJECT_ID'],
			];
			$processed++;
			$objectId = (int)$row['OBJECT_ID'];
			try
			{
				$claimToken = $this->markerService->recoverStaleClaim(
					$objectId,
					(string)$row['CLAIM_TOKEN'],
					MarkerStatus::from((string)$row['STATUS']),
				);
				if ($claimToken === null)
				{
					continue;
				}

				$recoveredClaims[$objectId] = [
					'userId' => (int)$row['USER_ID'],
					'parentId' => (int)$row['PARENT_ID'],
					'claimToken' => $claimToken,
					'status' => MarkerStatus::from((string)$row['STATUS']),
				];
			}
			catch (Throwable $exception)
			{
				$this->logger->error('upload cleanup recovery failed', [
					'objectId' => $objectId,
					'message' => $exception->getMessage(),
				]);
			}
		}
		$this->recoverClaimedFiles($recoveredClaims, $deadline);

		return $processed;
	}

	/**
	 * @param array<int, array{userId: int, parentId: int, claimToken: string, status: MarkerStatus}> $claims
	 */
	private function recoverClaimedFiles(array $claims, float $deadline): void
	{
		if ($claims === [] || microtime(true) >= $deadline)
		{
			return;
		}

		try
		{
			$files = File::getModelList([
				'filter' => [
					'@ID' => array_keys($claims),
					'=TYPE' => ObjectTable::TYPE_FILE,
				],
				'with' => ['STORAGE'],
			]);
		}
		catch (Throwable $exception)
		{
			foreach (array_keys($claims) as $objectId)
			{
				$this->logRecoveryFailure($objectId, $exception);
			}

			return;
		}

		$filesById = [];
		foreach ($files as $file)
		{
			$filesById[(int)$file->getId()] = $file;
		}
		if (microtime(true) >= $deadline)
		{
			return;
		}

		$userIdsByObjectId = [];
		$parentIds = [];
		$versions = [];
		foreach ($filesById as $objectId => $file)
		{
			$claim = $claims[$objectId];
			$userIdsByObjectId[$objectId] = $claim['userId'];
			$parentIds[$objectId] = $claim['parentId'];
			if ($claim['status'] === MarkerStatus::PublicationDeleting)
			{
				$versions[$objectId] = (int)$file->getGlobalContentVersion();
			}
		}

		$guardFailures = [];
		$guardChecked = false;
		try
		{
			$guardFailures = $this->guard->checkBatchForUsers(
				$filesById,
				$userIdsByObjectId,
				$parentIds,
				$versions,
			);
			$guardChecked = true;
		}
		catch (Throwable $exception)
		{
			foreach (array_keys($filesById) as $objectId)
			{
				$this->logRecoveryFailure($objectId, $exception);
			}
		}
		$securityContextsByObjectId = microtime(true) < $deadline
			? $this->preloadDeletionSecurityContexts(
				$filesById,
				$claims,
				$guardFailures,
				$guardChecked,
				$deadline,
			)
			: [];

		foreach ($claims as $objectId => $claim)
		{
			if (microtime(true) >= $deadline)
			{
				break;
			}
			try
			{
				$file = $filesById[$objectId] ?? null;
				if ($file === null)
				{
					$this->markerService->consumeClaim($objectId, $claim['claimToken']);

					continue;
				}
				if (!$guardChecked)
				{
					continue;
				}

				$userId = $claim['userId'];
				if (
					(int)$file->getCreatedBy() !== $userId
					|| ($guardFailures[$objectId] ?? null) !== null
				)
				{
					$this->markerService->revokeClaim($objectId, $claim['claimToken']);

					continue;
				}
				$securityContext = $securityContextsByObjectId[$objectId] ?? null;
				if ($securityContext === null)
				{
					continue;
				}
				// Deletion rights are enough for a destroy here, exactly as in the proof branch of
				// DiskUploaderController::resolveDeleteActor(): the claim, the author check and the
				// guard verdict already prove this is the author's own never-published upload.
				if (!$file->canMarkDeleted($securityContext))
				{
					$this->markerService->revokeClaim($objectId, $claim['claimToken']);

					continue;
				}

				if (microtime(true) >= $deadline)
				{
					break;
				}
				if ($file->delete($userId))
				{
					$this->markerService->consumeClaim($objectId, $claim['claimToken']);
				}
			}
			catch (Throwable $exception)
			{
				$this->logRecoveryFailure($objectId, $exception);
			}
		}
	}

	/**
	 * @param array<int, File> $filesById
	 * @param array<int, array{userId: int, parentId: int, claimToken: string, status: MarkerStatus}> $claims
	 * @param array<int, string|null> $guardFailures
	 * @return array<int, \Bitrix\Disk\Security\SecurityContext>
	 */
	private function preloadDeletionSecurityContexts(
		array $filesById,
		array $claims,
		array $guardFailures,
		bool $guardChecked,
		float $deadline,
	): array
	{
		if (!$guardChecked)
		{
			return [];
		}

		$groups = [];
		foreach ($filesById as $objectId => $file)
		{
			$claim = $claims[$objectId] ?? null;
			if (
				$claim === null
				|| (int)$file->getCreatedBy() !== $claim['userId']
				|| ($guardFailures[$objectId] ?? null) !== null
			)
			{
				continue;
			}

			$storage = $file->getStorage();
			$key = (int)$storage->getId() . ':' . $claim['userId'] . ':' . $claim['parentId'];
			$groups[$key]['storage'] = $storage;
			$groups[$key]['userId'] = $claim['userId'];
			$groups[$key]['parentId'] = $claim['parentId'];
			$groups[$key]['objectIds'][] = $objectId;
		}

		$securityContextsByObjectId = [];
		foreach ($groups as $group)
		{
			if (microtime(true) >= $deadline)
			{
				break;
			}
			try
			{
				$securityContext = $group['storage']->getSecurityContext($group['userId']);
				// The by-parent preload answers only for objects the parent query returns, so the rest
				// fall back to a query each. The by-id preload keeps one query per batch instead.
				if ($securityContext instanceof DiskSecurityContext)
				{
					$securityContext->preloadOperationsForObjectIds($group['objectIds']);
				}
				else
				{
					$securityContext->preloadOperationsForSpecifiedObjects(
						$group['parentId'],
						$group['objectIds'],
					);
				}
				foreach ($group['objectIds'] as $objectId)
				{
					$securityContextsByObjectId[$objectId] = $securityContext;
				}
			}
			catch (Throwable $exception)
			{
				foreach ($group['objectIds'] as $objectId)
				{
					$this->logRecoveryFailure($objectId, $exception);
				}
			}
		}

		return $securityContextsByObjectId;
	}

	private function logRecoveryFailure(int $objectId, Throwable $exception): void
	{
		$this->logger->error('upload cleanup recovery failed', [
			'objectId' => $objectId,
			'message' => $exception->getMessage(),
		]);
	}

	/**
	 * @param array{expiresAt: DateTime, objectId: int}|null $cursor
	 */
	private function removeOldTerminalRowsBatch(MarkerStatus $status, ?array &$cursor): int
	{
		$expiredBefore = DateTime::createFromTimestamp(time() - MarkerService::TOMBSTONE_GRACE_SECONDS);
		$filter = [
			'=STATUS' => $status->value,
			'<EXPIRES_AT' => $expiredBefore,
		];
		if ($cursor !== null)
		{
			$filter[] = [
				'LOGIC' => 'OR',
				['>EXPIRES_AT' => $cursor['expiresAt']],
				[
					'=EXPIRES_AT' => $cursor['expiresAt'],
					'>OBJECT_ID' => $cursor['objectId'],
				],
			];
		}
		$rows = UploadCleanupMarkerTable::getList([
			'select' => ['OBJECT_ID', 'EXPIRES_AT'],
			'filter' => $filter,
			'order' => ['EXPIRES_AT' => 'ASC', 'OBJECT_ID' => 'ASC'],
			'limit' => self::BATCH_SIZE,
		])->fetchAll();
		$objectIds = array_map('intval', array_column($rows, 'OBJECT_ID'));
		if ($objectIds === [])
		{
			return 0;
		}
		$lastRow = end($rows);
		$cursor = [
			'expiresAt' => $lastRow['EXPIRES_AT'],
			'objectId' => (int)$lastRow['OBJECT_ID'],
		];

		UploadCleanupMarkerTable::deleteByFilter([
			'@OBJECT_ID' => $objectIds,
			'=STATUS' => $status->value,
			'<EXPIRES_AT' => $expiredBefore,
		]);

		return count($objectIds);
	}
}

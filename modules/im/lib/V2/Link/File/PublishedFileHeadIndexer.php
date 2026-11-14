<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Link\File;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Search\HeadIndexOperationResult;
use Bitrix\Disk\Search\IndexManager;
use Bitrix\Disk\Storage;
use Bitrix\Im\Disk\ProxyType\Im;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\Disk\DiskCapability;
use Bitrix\Im\V2\Logger;
use Bitrix\Main\Config\Option;
use Closure;
use Throwable;

class PublishedFileHeadIndexer
{
	private const OPERATION_PUBLICATION = 'publication';
	private const OPERATION_RENAME = 'rename';

	private ?IndexManager $indexManager = null;

	/**
	 * @param int[] $savedDiskFileIds Ordered IDs of successfully saved file links.
	 */
	public function indexPublishedFiles(array $savedDiskFileIds): HeadIndexingOutcome
	{
		return $this->runGuarded(
			self::OPERATION_PUBLICATION,
			function (HeadIndexingOutcome $outcome, int $storageId) use ($savedDiskFileIds): void
			{
				$orderedIds = $this->getStableUniquePositiveIds($savedDiskFileIds);
				$limit = $this->getPublicationLimit();
				$selectedIds = array_slice($orderedIds, 0, $limit);
				$outcome->addCapped(count($orderedIds) - count($selectedIds));

				if ($selectedIds === [])
				{
					return;
				}

				$filesById = [];
				$selectedIdSet = array_fill_keys($selectedIds, true);
				foreach ($this->loadFiles($selectedIds) as $file)
				{
					if (!$file instanceof File)
					{
						continue;
					}

					$fileId = (int)$file->getId();
					if (isset($selectedIdSet[$fileId]) && !isset($filesById[$fileId]))
					{
						$filesById[$fileId] = $file;
					}
				}

				foreach ($selectedIds as $fileId)
				{
					$file = $filesById[$fileId] ?? null;
					if (!$file instanceof File)
					{
						$outcome->addForeignStorage();

						continue;
					}

					$this->processFile($file, $storageId, true, $outcome);
				}
			},
		);
	}

	public function updateAfterRename(BaseObject $object): HeadIndexingOutcome
	{
		return $this->runGuarded(
			self::OPERATION_RENAME,
			function (HeadIndexingOutcome $outcome, int $storageId) use ($object): void
			{
				$this->processFile($object, $storageId, false, $outcome);
			},
			// The rename handler listens to a portal-wide Disk event, so foreign
			// objects must be rejected by in-memory checks before the storage
			// lookup hits the database.
			function (HeadIndexingOutcome $outcome, int $storageId) use ($object): bool
			{
				if (!$object instanceof File || (int)$object->getStorageId() !== $storageId)
				{
					$outcome->addForeignStorage();

					return false;
				}

				return true;
			},
		);
	}

	private function runGuarded(
		string $operation,
		Closure $process,
		?Closure $preStorageCheck = null,
	): HeadIndexingOutcome
	{
		$outcome = new HeadIndexingOutcome();

		try
		{
			if (!$this->isEnabled())
			{
				return $outcome->addFlagOff();
			}

			$storageId = $this->getConfiguredStorageId();
			if ($storageId <= 0)
			{
				return $outcome->addInvalidStorage();
			}

			if ($preStorageCheck !== null && !$preStorageCheck($outcome, $storageId))
			{
				return $outcome;
			}

			$storage = $this->loadStorage($storageId);
			if (!$storage || !$this->isImStorage($storage))
			{
				return $outcome->addInvalidStorage();
			}

			$process($outcome, $storageId);
		}
		catch (Throwable)
		{
			$outcome->addError();
		}
		finally
		{
			if ($this->shouldWriteDiagnostic($operation, $outcome))
			{
				$this->writeDiagnosticSafely($operation, $outcome);
			}
		}

		return $outcome;
	}

	private function shouldWriteDiagnostic(string $operation, HeadIndexingOutcome $outcome): bool
	{
		$counters = $outcome->toArray();
		if ($counters[HeadIndexingOutcome::FLAG_OFF] > 0)
		{
			return false;
		}

		if ($operation !== self::OPERATION_RENAME)
		{
			return true;
		}

		// The rename handler fires for every Disk object on the portal, so a
		// rename rejected only by the in-memory foreign prefilter is routine
		// noise; log renames that actually touched an IM file or failed.
		return $counters[HeadIndexingOutcome::FOREIGN_STORAGE] === 0
			|| $counters[HeadIndexingOutcome::ATTEMPTS] > 0
			|| $counters[HeadIndexingOutcome::ERRORS] > 0;
	}

	private function processFile(
		File $file,
		int $storageId,
		bool $isPublication,
		HeadIndexingOutcome $outcome,
	): void
	{
		try
		{
			if ((int)$file->getStorageId() !== $storageId)
			{
				$outcome->addForeignStorage();

				return;
			}

			$outcome->addAttempt();

			$result = $isPublication
				? $this->publishFile($file)
				: $this->updateFileAfterRename($file);
			$this->aggregateResult($result, $outcome);
		}
		catch (Throwable)
		{
			$outcome->addError();
		}
	}

	private function aggregateResult(
		HeadIndexOperationResult $result,
		HeadIndexingOutcome $outcome,
	): void
	{
		if (!$result->isSuccess())
		{
			$outcome->addError();

			return;
		}

		switch ($result->getOutcome())
		{
			case HeadIndexOperationResult::OUTCOME_CREATED:
				$outcome->addCreated();

				break;
			case HeadIndexOperationResult::OUTCOME_UPDATED:
				$outcome->addUpdated();

				break;
			case HeadIndexOperationResult::OUTCOME_NO_OP:
				$outcome->addNoOp();

				break;
			case HeadIndexOperationResult::OUTCOME_SKIPPED:
				$outcome->addSkipped();

				break;
			default:
				$outcome->addError();
		}
	}

	/**
	 * @param mixed[] $ids
	 * @return int[]
	 */
	private function getStableUniquePositiveIds(array $ids): array
	{
		$result = [];
		$seen = [];

		foreach ($ids as $id)
		{
			if (!is_int($id) || $id <= 0 || isset($seen[$id]))
			{
				continue;
			}

			$seen[$id] = true;
			$result[] = $id;
		}

		return $result;
	}

	private function writeDiagnosticSafely(string $operation, HeadIndexingOutcome $outcome): void
	{
		try
		{
			$this->writeDiagnostic($operation, $outcome);
		}
		catch (Throwable)
		{
		}
	}

	protected function isEnabled(): bool
	{
		return Features::isDiskImHeadIndexingEnabled() && DiskCapability::isHeadIndexAvailable();
	}

	protected function getConfiguredStorageId(): int
	{
		return (int)Option::get('im', 'disk_storage_id', 0);
	}

	protected function loadStorage(int $storageId): ?Storage
	{
		return Storage::loadById($storageId);
	}

	protected function isImStorage(Storage $storage): bool
	{
		return $storage->getProxyType() instanceof Im;
	}

	protected function getPublicationLimit(): int
	{
		return HeadIndexingOutcome::normalizeLimit(
			Option::get('im', 'disk_im_head_indexing_limit', HeadIndexingOutcome::DEFAULT_LIMIT),
		);
	}

	/**
	 * @param int[] $ids
	 * @return File[]
	 */
	protected function loadFiles(array $ids): array
	{
		return File::getModelList([
			'filter' => ['@ID' => $ids],
			'with' => ['CREATE_USER'],
		]);
	}

	protected function publishFile(File $file): HeadIndexOperationResult
	{
		return $this->getIndexManager()->publishFileToHeadIndex($file);
	}

	protected function updateFileAfterRename(File $file): HeadIndexOperationResult
	{
		return $this->getIndexManager()->updateFileHeadIndexIfExists($file);
	}

	protected function writeDiagnostic(string $operation, HeadIndexingOutcome $outcome): void
	{
		(new Logger('disk-im-head-indexing'))->logArray(
			array_merge(
				[
					'type' => 'disk-im-head-indexing',
					'operation' => $operation,
				],
				$outcome->toArray(),
			),
		);
	}

	private function getIndexManager(): IndexManager
	{
		$this->indexManager ??= new IndexManager();

		return $this->indexManager;
	}
}

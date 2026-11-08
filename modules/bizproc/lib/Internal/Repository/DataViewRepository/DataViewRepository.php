<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\DataViewRepository;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewFreshness;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewStatus;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewSummary;
use Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity;
use Bitrix\Bizproc\Internal\Exception\DataView\SaveDataViewException;
use Bitrix\Bizproc\Internal\Model\EO_StorageDataView;
use Bitrix\Bizproc\Internal\Model\StorageDataViewTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\DataViewMapper;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

class DataViewRepository implements DataViewRepositoryInterface
{
	private const LOCK_PREFIX = 'bizproc_dataview_';

	private const LOCK_TIMEOUT = 5;

	public function __construct(private readonly DataViewMapper $mapper)
	{
	}

	public function getByStorageTypeId(int $storageTypeId): ?DataView
	{
		$ormModel = $this->findOrmByStorageTypeId($storageTypeId);

		return $ormModel !== null ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	public function getFreshness(int $storageTypeId): ?DataViewFreshness
	{
		$row = StorageDataViewTable::query()
			->setSelect(['STATUS'])
			->where('STORAGE_TYPE_ID', $storageTypeId)
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return new DataViewFreshness(
			status: DataViewStatus::fromString($row['STATUS'] !== null ? (string)$row['STATUS'] : null),
		);
	}

	public function getSummariesByOwner(int $templateId, string $activityName): array
	{
		$rows = StorageDataViewTable::query()
			->setSelect(['ID', 'STORAGE_TYPE_ID', 'STATUS'])
			->where('OWNER_TEMPLATE_ID', $templateId)
			->where('OWNER_ACTIVITY_NAME', $activityName)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(
			static fn (array $row): DataViewSummary => new DataViewSummary(
				id: (int)$row['ID'],
				storageTypeId: (int)$row['STORAGE_TYPE_ID'],
				status: DataViewStatus::fromString($row['STATUS'] !== null ? (string)$row['STATUS'] : null),
			),
			$rows,
		);
	}

	public function getStorageTypeIds(): array
	{
		$rows = StorageDataViewTable::query()
			->setSelect(['STORAGE_TYPE_ID'])
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['STORAGE_TYPE_ID'], $rows);
	}

	public function getOwnerTemplateIds(): array
	{
		$rows = StorageDataViewTable::query()
			->setSelect(['STORAGE_TYPE_ID', 'OWNER_TEMPLATE_ID'])
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		$ownerTemplateIds = [];
		foreach ($rows as $row)
		{
			$ownerTemplateIds[(int)$row['STORAGE_TYPE_ID']] =
				$row['OWNER_TEMPLATE_ID'] !== null ? (int)$row['OWNER_TEMPLATE_ID'] : null
			;
		}

		return $ownerTemplateIds;
	}

	public function save(DataView $dataView): DataView
	{
		try
		{
			$ormModel = $this->mapper->convertToOrm($dataView);
			$result = $ormModel->save();

			if (!$result->isSuccess())
			{
				throw new SaveDataViewException($result->getErrors()[0]->getMessage());
			}

			if ($dataView->isNew())
			{
				return $dataView->withId((int)$result->getId());
			}

			return $dataView;
		}
		catch (SaveDataViewException $exception)
		{
			throw $exception;
		}
		catch (\Throwable $exception)
		{
			throw new SaveDataViewException($exception->getMessage());
		}
	}

	public function deleteByStorageTypeId(int $storageTypeId): void
	{
		StorageDataViewTable::deleteByFilter(['STORAGE_TYPE_ID' => $storageTypeId]);
	}

	public function markActual(DataView $dataView, DateTime $materializedAt, int $materializedBy): void
	{
		$id = $this->resolveId($dataView);
		if ($id === null)
		{
			return;
		}

		$this->update($id, [
			'STATUS' => DataViewStatus::Actual->value,
			'MATERIALIZED_AT' => $materializedAt,
			'MATERIALIZED_BY' => $materializedBy,
			'ERROR_TEXT' => null,
		]);
	}

	public function markBroken(DataView $dataView, string $errorText): void
	{
		$id = $this->resolveId($dataView);
		if ($id === null)
		{
			return;
		}

		$this->update($id, [
			'STATUS' => DataViewStatus::SourceUnavailable->value,
			'ERROR_TEXT' => $errorText,
		]);
	}

	public function appendDeletionMarks(DataView $dataView, array $identities): void
	{
		if ($identities === [])
		{
			return;
		}

		$ormModel = $dataView->getId() !== null
			? EO_StorageDataView::wakeUp($dataView->getId())
			: $this->findOrmByStorageTypeId($dataView->getStorageTypeId());

		if ($ormModel === null)
		{
			return;
		}

		$fresh = StorageDataViewTable::getById($ormModel->getId())->fetchObject();
		if ($fresh === null)
		{
			return;
		}

		$stored = $this->mapper->convertFromOrm($fresh)->getDeletionMarks();

		// The column is rewritten in full on every deletion, so marks are kept unique by row
		// identity: a repeated deletion of the same pair must not append another entry.
		$marks = [];
		foreach ([...$stored, ...$identities] as $mark)
		{
			$identity = RowIdentity::fromArray((array)$mark);
			if ($identity !== null)
			{
				$marks[$identity->serialize()] = $identity->toArray();
			}
		}

		if (count($marks) === count($stored))
		{
			return;
		}

		$this->update((int)$fresh->getId(), [
			'DELETION_MARKS' => (string)json_encode(
				array_values($marks),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
			),
		]);
	}

	public function acquireCatalogLock(): bool
	{
		return $this->acquireLock(self::LOCK_PREFIX . 'catalog');
	}

	public function releaseCatalogLock(): void
	{
		$this->releaseLock(self::LOCK_PREFIX . 'catalog');
	}

	public function acquireViewLock(int $storageTypeId): bool
	{
		return $this->acquireLock(self::LOCK_PREFIX . $storageTypeId);
	}

	public function releaseViewLock(int $storageTypeId): void
	{
		$this->releaseLock(self::LOCK_PREFIX . $storageTypeId);
	}

	protected function acquireLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, self::LOCK_TIMEOUT);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	private function findOrmByStorageTypeId(int $storageTypeId): ?EO_StorageDataView
	{
		return StorageDataViewTable::query()
			->setSelect(['*'])
			->where('STORAGE_TYPE_ID', $storageTypeId)
			->setLimit(1)
			->fetchObject()
		;
	}

	private function resolveId(DataView $dataView): ?int
	{
		if ($dataView->getId() !== null)
		{
			return $dataView->getId();
		}

		$ormModel = $this->findOrmByStorageTypeId($dataView->getStorageTypeId());

		return $ormModel?->getId();
	}

	private function update(int $id, array $fields): void
	{
		$result = StorageDataViewTable::update($id, $fields);
		if (!$result->isSuccess())
		{
			throw new SaveDataViewException($result->getErrors()[0]->getMessage());
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DataView;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewStatus;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Exception\DataView\DataViewMaterializeFailedException;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver;
use Bitrix\Bizproc\Internal\Service\DataView\DataViewValidator;
use Bitrix\Bizproc\Internal\Service\DataView\MaterializeService;
use Bitrix\Bizproc\Public\Command\StorageField\AddStorageFieldCommand;
use Bitrix\Bizproc\Public\Command\StorageField\AddStorageFieldCommandHandler;
use Bitrix\Bizproc\Public\Command\StorageField\DeleteStorageFieldCommand;
use Bitrix\Bizproc\Public\Command\StorageField\DeleteStorageFieldCommandHandler;
use Bitrix\Bizproc\Public\Command\StorageField\UpdateStorageFieldCommand;
use Bitrix\Bizproc\Public\Command\StorageField\UpdateStorageFieldCommandHandler;
use Bitrix\Bizproc\Public\Command\StorageType\AddStorageTypeCommand;
use Bitrix\Bizproc\Public\Command\StorageType\AddStorageTypeCommandHandler;
use Bitrix\Bizproc\Public\Command\StorageType\UpdateStorageTypeCommand;
use Bitrix\Bizproc\Public\Command\StorageType\UpdateStorageTypeCommandHandler;
use Bitrix\Bizproc\Public\DataView\Exception\RecomputeInProgressException;
use Bitrix\Bizproc\Public\Provider\SourceCatalogProvider;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;

final class SaveDataViewCommandHandler
{
	private DataViewValidator $validator;
	private MaterializeService $materializeService;
	private DataViewRepositoryInterface $dataViewRepository;
	private StorageTypeRepositoryInterface $storageTypeRepository;
	private StorageFieldRepositoryInterface $storageFieldRepository;
	private SourceCatalogProvider $sourceCatalog;

	public function __construct()
	{
		$locator = ServiceLocator::getInstance();
		$this->validator = $locator->get('bizproc.service.dataView.validator');
		$this->sourceCatalog = new SourceCatalogProvider();
		$this->materializeService = $locator->get('bizproc.service.dataView.materializeService');
		$this->dataViewRepository = Container::getDataViewRepository();
		$this->storageTypeRepository = Container::getStorageTypeRepository();
		$this->storageFieldRepository = Container::getStorageFieldRepository();
	}

	/**
	 * @return array{view: DataView, rowsCount: int}
	 */
	public function __invoke(SaveDataViewCommand $command): array
	{
		$this->assertOwnerPair($command);

		$view = $this->saveUnderCatalogLock($command);

		try
		{
			$rowsCount = $this->materializeService->materialize($view, (int)$view->getUpdatedBy());
		}
		catch (\Throwable $exception)
		{
			throw new DataViewMaterializeFailedException($view, $exception);
		}

		$storageTypeId = $view->getStorageTypeId();

		return [
			'view' => $this->dataViewRepository->getByStorageTypeId($storageTypeId) ?? $view,
			'rowsCount' => $rowsCount,
		];
	}

	/**
	 * The catalog lock covers validation as well: the chain depth is checked against the whole
	 * catalog, so a concurrent save must not slip in between the check and the commit.
	 */
	private function saveUnderCatalogLock(SaveDataViewCommand $command): DataView
	{
		if (!$this->dataViewRepository->acquireCatalogLock())
		{
			throw RecomputeInProgressException::forCatalog();
		}

		try
		{
			$existing = $this->resolveExistingView($command);

			// an update without an explicit owner inherits it from the stored view, as resolveOwner() does
			$ownerTemplateId = $command->ownerTemplateId ?? $existing?->getOwnerTemplateId();
			ColumnResolver::assertTemplateSourcesOwned($command->definition, $ownerTemplateId);
			$this->validator->assertReferencedViewsOwned($command->definition, $ownerTemplateId);
			$this->sourceCatalog->assertDefinitionSourcesAvailable(
				$command->definition,
				$command->actorId,
				$ownerTemplateId,
			);

			$definition = $command->definition;
			$columns = $this->validator->assertValid(
				$definition,
				$command->storageTypeId,
				$existing?->getDefinition(),
			);
			$definition['columns'] = $columns;

			return $this->persistDefinition($command, $existing, $definition, $columns);
		}
		finally
		{
			$this->dataViewRepository->releaseCatalogLock();
		}
	}

	private function persistDefinition(
		SaveDataViewCommand $command,
		?DataView $existing,
		array $definition,
		array $columns,
	): DataView
	{

		$lockedStorageTypeId = $existing !== null ? (int)$command->storageTypeId : null;

		if ($lockedStorageTypeId !== null && !$this->dataViewRepository->acquireViewLock($lockedStorageTypeId))
		{
			throw RecomputeInProgressException::forStorageType($lockedStorageTypeId);
		}

		$connection = Application::getConnection();

		try
		{
			$connection->startTransaction();

			try
			{
				$storageTypeId = $this->syncStorageType($command, $existing);
				$this->syncFields($storageTypeId, $columns);

				$snapshot = $lockedStorageTypeId !== null
					? ($this->dataViewRepository->getByStorageTypeId($lockedStorageTypeId) ?? $existing)
					: null;

				[$ownerTemplateId, $ownerActivityName] = $this->resolveOwner($command, $snapshot);

				$view = $this->dataViewRepository->save(new DataView(
					id: $snapshot?->getId(),
					storageTypeId: $storageTypeId,
					definition: $definition,
					status: DataViewStatus::NotMaterialized,
					errorText: null,
					deletionMarks: $snapshot?->getDeletionMarks() ?? [],
					materializedAt: $snapshot?->getMaterializedAt(),
					materializedBy: $snapshot?->getMaterializedBy(),
					ownerTemplateId: $ownerTemplateId,
					ownerActivityName: $ownerActivityName,
					createdBy: $snapshot?->getCreatedBy() ?? $command->actorId,
					updatedBy: $command->actorId,
				));
			}
			catch (\Throwable $exception)
			{
				$this->rollbackPreservingCause($connection);

				throw $exception;
			}

			$connection->commitTransaction();
		}
		finally
		{
			if ($lockedStorageTypeId !== null)
			{
				$this->dataViewRepository->releaseViewLock($lockedStorageTypeId);
			}
		}

		return $view;
	}

	private function rollbackPreservingCause(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}

	private function assertOwnerPair(SaveDataViewCommand $command): void
	{
		if ($command->ownerTemplateId === null && $command->ownerActivityName === null)
		{
			return;
		}

		if (
			$command->ownerTemplateId === null
			|| $command->ownerActivityName === null
			|| $command->ownerTemplateId <= 0
			|| $command->ownerActivityName === ''
		)
		{
			throw new InvalidDataViewDefinitionException(
				'Data view owner must be set as a complete pair of template id and activity name.',
			);
		}
	}

	private function resolveOwner(SaveDataViewCommand $command, ?DataView $snapshot): array
	{
		$ownerProvided = $command->ownerTemplateId !== null || $command->ownerActivityName !== null;

		if ($snapshot === null)
		{
			return [$command->ownerTemplateId, $command->ownerActivityName];
		}

		if (!$ownerProvided)
		{
			return [$snapshot->getOwnerTemplateId(), $snapshot->getOwnerActivityName()];
		}

		if (
			$snapshot->getOwnerTemplateId() !== null
			&& (
				$snapshot->getOwnerTemplateId() !== $command->ownerTemplateId
				|| $snapshot->getOwnerActivityName() !== $command->ownerActivityName
			)
		)
		{
			throw new InvalidDataViewDefinitionException('Data view owner is immutable.');
		}

		return [$command->ownerTemplateId, $command->ownerActivityName];
	}

	private function resolveExistingView(SaveDataViewCommand $command): ?DataView
	{
		if ($command->storageTypeId === null || $command->storageTypeId <= 0)
		{
			return null;
		}

		$existing = $this->dataViewRepository->getByStorageTypeId($command->storageTypeId);
		if ($existing === null && $this->storageTypeRepository->exists($command->storageTypeId))
		{
			throw new InvalidDataViewDefinitionException(
				sprintf('Storage type %d is not a data view.', $command->storageTypeId),
			);
		}

		return $existing;
	}

	private function syncStorageType(SaveDataViewCommand $command, ?DataView $existing): int
	{
		if ($existing !== null)
		{
			$storageType = (new StorageType())
				->setId((int)$command->storageTypeId)
				->setTitle($command->title)
				->setDescription($command->description)
				->setCode($command->code)
				->setUpdatedBy($command->actorId)
			;

			(new UpdateStorageTypeCommandHandler())(
				new UpdateStorageTypeCommand(updatedBy: $command->actorId, storageType: $storageType)
			);

			return (int)$command->storageTypeId;
		}

		$storageType = (new StorageType())
			->setTitle($command->title)
			->setDescription($command->description)
			->setCode($command->code)
		;

		$created = (new AddStorageTypeCommandHandler())(
			new AddStorageTypeCommand(createdBy: $command->actorId, storageType: $storageType)
		);

		return (int)$created->getId();
	}

	private function syncFields(int $storageTypeId, array $columns): void
	{
		$existingByCode = [];
		foreach ($this->storageFieldRepository->getByStorageId($storageTypeId, ['*']) as $field)
		{
			$existingByCode[(string)$field->getCode()] = $field;
		}

		$sort = 100;
		foreach ($columns as $column)
		{
			$code = $column['code'];
			$existingField = $existingByCode[$code] ?? null;
			$storageField = (new StorageField())
				->setStorageId($storageTypeId)
				->setCode($code)
				// the editor does not carry the description of a column, so a save keeps the stored one
				->setDescription($column['description'] ?? $existingField?->getDescription())
				->setName($column['title'] !== '' ? $column['title'] : $code)
				->setType($column['type'])
				->setMultiple($column['multiple'])
				->setMandatory(false)
				->setSort($sort)
			;
			$sort += 100;

			if ($existingField === null)
			{
				(new AddStorageFieldCommandHandler())(
					new AddStorageFieldCommand(storageField: $storageField)
				);

				continue;
			}

			if ((string)$existingField->getType() !== (string)$storageField->getType())
			{
				$this->recreateField($storageTypeId, (int)$existingField->getId(), $storageField);

				continue;
			}

			if ($this->isFieldUpToDate($existingField, $storageField))
			{
				continue;
			}

			$storageField->setId($existingField->getId());
			(new UpdateStorageFieldCommandHandler())(
				new UpdateStorageFieldCommand(storageField: $storageField)
			);
		}
	}

	/**
	 * The type of a storage field is immutable, so a column that changed its type gets its field
	 * created anew: the data of a data view is derived and is rewritten by the materialization that
	 * follows the save. Value writers cache the field map of the storage, so the dropped field must
	 * not stay in it.
	 */
	private function recreateField(int $storageTypeId, int $existingFieldId, StorageField $storageField): void
	{
		(new DeleteStorageFieldCommandHandler())(new DeleteStorageFieldCommand($existingFieldId));
		(new AddStorageFieldCommandHandler())(new AddStorageFieldCommand(storageField: $storageField));

		Container::getStorageFieldValueRepository()?->resetFieldMapCache($storageTypeId);
	}

	/**
	 * Compares exactly the attributes the update path writes: CODE is immutable for existing fields
	 * (the mapper never rewrites it) and a changed TYPE is handled by recreating the field.
	 */
	private function isFieldUpToDate(StorageField $existing, StorageField $target): bool
	{
		return $existing->getSort() === $target->getSort()
			&& $existing->getName() === $target->getName()
			&& (string)$existing->getDescription() === (string)$target->getDescription()
			&& $existing->getMultiple() === $target->getMultiple()
			&& $existing->getMandatory() === $target->getMandatory()
			&& ($existing->getSettings() ?? []) === ($target->getSettings() ?? []);
	}
}

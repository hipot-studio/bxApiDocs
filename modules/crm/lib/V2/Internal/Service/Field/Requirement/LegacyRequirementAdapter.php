<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\Entity\FieldAttributeTable;
use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Attribute\FieldAttributePhaseGroupType;
use Bitrix\Crm\Attribute\FieldAttributeType;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Error;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\EntityType;

final class LegacyRequirementAdapter
{
	private readonly StorageGatewayInterface $storage;
	private readonly WriteFailureLogger $failureLogger;

	public function __construct(
		?StorageGatewayInterface $storage = null,
		?WriteFailureLogger $failureLogger = null,
	)
	{
		$this->storage = $storage ?? new OrmRequirementStorageGateway();
		$this->failureLogger = $failureLogger ?? new WriteFailureLogger();
	}

	public function read(
		EntityType $entityType,
		?int $categoryId,
		string $legacyFieldName,
	): LegacyRequirementRule
	{
		$rows = FieldAttributeTable::query()
			->setSelect([
				'ID',
				'START_PHASE',
				'FINISH_PHASE',
				'PHASE_GROUP_TYPE_ID',
			])
			->where('ENTITY_TYPE_ID', $entityType->getId())
			->where('ENTITY_SCOPE', FieldAttributeManager::getEntityScopeByCategory($categoryId))
			->where('TYPE_ID', FieldAttributeType::REQUIRED)
			->where('FIELD_NAME', $legacyFieldName)
			->where('IS_CUSTOM_FIELD', str_starts_with($legacyFieldName, 'UF_') ? 'Y' : 'N')
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		if ($rows === [])
		{
			return LegacyRequirementRule::none();
		}

		$hasAll = false;
		$ranges = [];
		foreach ($rows as $row)
		{
			$phaseGroupTypeId = (int)$row['PHASE_GROUP_TYPE_ID'];
			$startPhaseId = $row['START_PHASE'];
			$finishPhaseId = $row['FINISH_PHASE'];

			if (!FieldAttributePhaseGroupType::isDefined($phaseGroupTypeId))
			{
				throw $this->configurationConflict();
			}

			if ($phaseGroupTypeId === FieldAttributePhaseGroupType::ALL)
			{
				if ($startPhaseId !== '' || $finishPhaseId !== '')
				{
					throw $this->configurationConflict();
				}

				$hasAll = true;

				continue;
			}

			if ($startPhaseId === '' || $finishPhaseId === '')
			{
				throw $this->configurationConflict();
			}

			$ranges[] = new LegacyRequirementRange(
				$phaseGroupTypeId,
				$startPhaseId,
				$finishPhaseId,
			);
		}

		if ($hasAll && $ranges !== [])
		{
			throw $this->configurationConflict();
		}

		return $hasAll ? LegacyRequirementRule::all() : LegacyRequirementRule::ranges($ranges);
	}

	public function replace(
		EntityType $entityType,
		?int $categoryId,
		string $legacyFieldName,
		LegacyRequirementRule $rule,
		string $operation,
	): void
	{
		$key = new StorageKey(
			$entityType->getId(),
			FieldAttributeManager::getEntityScopeByCategory($categoryId),
			$legacyFieldName,
			str_starts_with($legacyFieldName, 'UF_'),
		);

		try
		{
			$this->replaceStorage($key, $rule, $operation);
		}
		finally
		{
			FieldAttributeManager::invalidateRuntimeCache($key->getEntityTypeId(), $key->getEntityScope());
		}
	}

	private function replaceStorage(
		StorageKey $key,
		LegacyRequirementRule $rule,
		string $operation,
	): void
	{
		$ordinal = 0;

		foreach ($this->storage->selectIds($key) as $id)
		{
			$result = $this->storage->delete($id);
			$ordinal++;
			if (!$result->isSuccess())
			{
				$this->failureLogger->log($operation, 'delete', $key, $ordinal, $result);

				throw new Exception(Error::WRITE_FAILED);
			}
		}

		if ($rule->isAll())
		{
			$result = $this->storage->add($key, FieldAttributePhaseGroupType::ALL, '', '');
			$ordinal++;
			if (!$result->isSuccess())
			{
				$this->failureLogger->log($operation, 'add', $key, $ordinal, $result);

				throw new Exception(Error::WRITE_FAILED);
			}

			return;
		}

		foreach ($rule->getRanges() as $range)
		{
			$result = $this->storage->add(
				$key,
				$range->getPhaseGroupTypeId(),
				$range->getStartPhaseId(),
				$range->getFinishPhaseId(),
			);
			$ordinal++;
			if (!$result->isSuccess())
			{
				$this->failureLogger->log($operation, 'add', $key, $ordinal, $result);

				throw new Exception(Error::WRITE_FAILED);
			}
		}
	}

	private function configurationConflict(): Exception
	{
		return new Exception(Error::CONFIGURATION_CONFLICT);
	}
}

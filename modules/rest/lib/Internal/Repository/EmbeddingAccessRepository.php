<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Exception\ArgumentException;
use Bitrix\Rest\Internal\Model\EmbeddingAccessTable;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessCodeNormalizer;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\PlacementTable;

class EmbeddingAccessRepository
{
	public function findAccessibleEmbeddingIds(array $embeddingIds, int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$embeddingIds = $this->normalizeEmbeddingIds($embeddingIds);
		if ($embeddingIds === [])
		{
			return [];
		}

		$accessCodes = \CAccess::GetUserCodesArray($userId);
		if ($accessCodes === [])
		{
			return [];
		}
		$accessCodes[] = EmbeddingAccessCodeNormalizer::ACCESS_CODE_AUTHORIZED;

		$accessibleIds = [];
		$result = EmbeddingAccessTable::query()
			->setSelect(['EMBEDDING_ID'])
			->whereIn('EMBEDDING_ID', $embeddingIds)
			->whereIn('ACCESS_CODE', $accessCodes)
			->exec()
		;
		while ($row = $result->fetch())
		{
			$accessibleIds[(int)$row['EMBEDDING_ID']] = true;
		}

		return array_values(array_filter(
			$embeddingIds,
			static fn(int $embeddingId): bool => isset($accessibleIds[$embeddingId]),
		));
	}

	public function replaceForEmbeddings(
		string $moduleId,
		string $entityType,
		array $accessCodesByEmbeddingId,
	): void
	{
		$accessCodesByEmbeddingId = $this->keyByEmbeddingId($accessCodesByEmbeddingId);
		$embeddingIds = array_keys($accessCodesByEmbeddingId);

		if (empty($embeddingIds))
		{
			return;
		}

		$embeddingIds = $this->assertOwnedBy($embeddingIds, $moduleId, $entityType);
		if (empty($embeddingIds))
		{
			return;
		}

		try
		{
			$this->replaceSet($embeddingIds, $accessCodesByEmbeddingId);
		}
		catch (PersistenceException $exception)
		{
			$this->dropCodesAfterFailure($embeddingIds);

			throw $exception;
		}
	}

	public function deleteForEmbedding(int $embeddingId): void
	{
		$this->deleteForEmbeddings([$embeddingId]);
	}

	public function deleteForApp(int $appId): void
	{
		$this->deleteForEmbeddings($this->findEmbeddingIdsByApp($appId));
	}

	public function deleteForEmbeddings(array $embeddingIds): void
	{
		if (empty($embeddingIds))
		{
			return;
		}

		EmbeddingAccessTable::deleteByFilter(['@EMBEDDING_ID' => $embeddingIds]);
	}

	public function findEmbeddingIdsByApp(int $appId): array
	{
		$rows = PlacementTable::query()
			->setSelect(['ID'])
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->where('MODULE_ID', EmbeddingOwnerResolver::MODULE_ID_REST)
							->where('ENTITY_TYPE', EmbeddingOwnerResolver::ENTITY_TYPE_APP)
							->where('ENTITY_ID', (string)$appId),
					)
					->where(
						Query::filter()
							->where('APP_ID', $appId)
							->whereNull('ENTITY_ID')
							->where('MODULE_ID', EmbeddingOwnerResolver::MODULE_ID_REST)
							->where('ENTITY_TYPE', EmbeddingOwnerResolver::ENTITY_TYPE_APP),
					),
			)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	private function normalizeEmbeddingIds(array $embeddingIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $embeddingIds),
			static fn(int $embeddingId): bool => $embeddingId > 0,
		)));
	}

	private function keyByEmbeddingId(array $accessCodesByEmbeddingId): array
	{
		$byEmbeddingId = [];
		foreach ($accessCodesByEmbeddingId as $embeddingId => $accessCodes)
		{
			$byEmbeddingId[(int)$embeddingId] = $accessCodes;
		}

		return $byEmbeddingId;
	}

	private function assertOwnedBy(array $embeddingIds, string $moduleId, string $entityType): array
	{
		$expectedModuleId = EmbeddingOwnerResolver::canonicalizeOwnerPart($moduleId);
		$expectedEntityType = EmbeddingOwnerResolver::canonicalizeOwnerPart($entityType);

		$rows = PlacementTable::query()
			->setSelect(['ID', 'MODULE_ID', 'ENTITY_TYPE'])
			->whereIn('ID', $embeddingIds)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$rowModuleId = EmbeddingOwnerResolver::canonicalizeOwnerPart((string)$row['MODULE_ID']);
			$rowEntityType = EmbeddingOwnerResolver::canonicalizeOwnerPart((string)$row['ENTITY_TYPE']);

			if ($rowModuleId !== $expectedModuleId || $rowEntityType !== $expectedEntityType)
			{
				throw new ArgumentException(
					sprintf(
						'Embedding %d belongs to %s/%s, not to %s/%s',
						(int)$row['ID'],
						$rowModuleId,
						$rowEntityType,
						$expectedModuleId,
						$expectedEntityType,
					),
					'accessCodesByEmbeddingId',
				);
			}
		}

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	private function replaceSet(array $embeddingIds, array $accessCodesByEmbeddingId): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->deleteForEmbeddings($embeddingIds);

			$rows = [];
			foreach ($embeddingIds as $embeddingId)
			{
				foreach ($accessCodesByEmbeddingId[$embeddingId] as $accessCode)
				{
					$rows[] = [
						'EMBEDDING_ID' => $embeddingId,
						'ACCESS_CODE' => $accessCode,
					];
				}
			}

			$this->insertRows($rows);

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollbackTransaction($connection);

			throw new PersistenceException($exception->getMessage(), previous: $exception);
		}
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}

	private function dropCodesAfterFailure(array $embeddingIds): void
	{
		try
		{
			$this->deleteForEmbeddings($embeddingIds);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function insertRows(array $rows): void
	{
		if (empty($rows))
		{
			return;
		}

		$result = EmbeddingAccessTable::addInsertIgnoreMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new PersistenceException(implode(', ', $result->getErrorMessages()));
		}
	}
}

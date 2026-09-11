<?php

namespace Bitrix\Sign\Repository\Document;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Sign\Internal\Document\Folder\DocumentFolderRelationTable;
use Bitrix\Sign\Type\Document\Folder\EntityType;

/**
 * Access to the polymorphic company safe folder relation table (b_sign_document_folder_relation).
 * A folder has a single root relation (PARENT_ID = 0); a safe member (grid row) has a single
 * relation whose PARENT_ID is its folder id (PARENT_ID = 0 means "No folder").
 */
class SafeFolderRelationRepository
{
	/**
	 * Locks folder relation rows in a stable order for the current transaction.
	 *
	 * @param list<int> $folderIds
	 */
	public function lockFolderRelations(array $folderIds): bool
	{
		$folderIds = array_values(array_unique(array_filter(
			array_map('intval', $folderIds),
			static fn(int $folderId): bool => $folderId > 0,
		)));
		if ($folderIds === [])
		{
			return true;
		}

		sort($folderIds, SORT_NUMERIC);
		$sql = (new SqlExpression(
			'SELECT ENTITY_ID FROM ?#'
			. ' WHERE ENTITY_TYPE = ?s AND ENTITY_ID IN (?@)'
			. ' ORDER BY ENTITY_ID FOR UPDATE',
			DocumentFolderRelationTable::getTableName(),
			EntityType::FOLDER->value,
			$folderIds,
		))->compile();
		$rows = Application::getConnection()->query($sql)->fetchAll();

		return count($rows) === count($folderIds);
	}

	public function add(
		int $entityId,
		EntityType $entityType,
		int $parentId,
		int $depthLevel,
		int $createdById,
	): Result
	{
		$result = DocumentFolderRelationTable::add([
			'ENTITY_ID' => $entityId,
			'PARENT_ID' => $parentId,
			'ENTITY_TYPE' => $entityType->value,
			'DEPTH_LEVEL' => $depthLevel,
			'CREATED_BY_ID' => $createdById,
		]);

		if (!$result->isSuccess())
		{
			return (new Result())->addErrors($result->getErrors());
		}

		return (new Result())->setData(['id' => $result->getId()]);
	}

	/**
	 * Moves the given entities under a new parent folder (PARENT_ID = 0 means "No folder").
	 * For safe members it makes sure every member has a relation row, so a later folder deletion can
	 * reassign the members it holds.
	 *
	 * @param list<int> $entityIds
	 */
	public function updateParent(int $parentId, array $entityIds, EntityType $entityType): Result
	{
		if (empty($entityIds))
		{
			return new Result();
		}

		try
		{
			$depthLevel = $this->calculateDepthLevelForNewChild($parentId);

			if ($entityType === EntityType::MEMBER)
			{
				$this->ensureMemberRelations($entityIds, $parentId, $depthLevel);
			}

			DocumentFolderRelationTable::updateByFilter(
				['@ENTITY_ID' => $entityIds, '=ENTITY_TYPE' => $entityType->value],
				['DEPTH_LEVEL' => $depthLevel, 'PARENT_ID' => $parentId],
			);
		}
		catch (ArgumentException | ObjectNotFoundException $e)
		{
			return (new Result())->addError(new Error($e->getMessage()));
		}

		return new Result();
	}

	public function updateParentByParentId(
		int $parentId,
		int $sourceParentId,
		EntityType $entityType,
	): Result
	{
		try
		{
			$depthLevel = $this->calculateDepthLevelForNewChild($parentId);
			DocumentFolderRelationTable::updateByFilter(
				[
					'=PARENT_ID' => $sourceParentId,
					'=ENTITY_TYPE' => $entityType->value,
				],
				[
					'DEPTH_LEVEL' => $depthLevel,
					'PARENT_ID' => $parentId,
				],
			);
		}
		catch (ArgumentException | ObjectNotFoundException $e)
		{
			return (new Result())->addError(new Error($e->getMessage()));
		}

		return new Result();
	}

	/**
	 * Creates a member relation row for any member that does not have one yet, so the polymorphic
	 * relation table contains every member moved after the one-shot backfill agent. Existing rows are
	 * left untouched here (updateParent updates them).
	 *
	 * @param list<int> $memberIds
	 */
	private function ensureMemberRelations(array $memberIds, int $parentId, int $depthLevel): void
	{
		$existingIds = [];
		$rows = DocumentFolderRelationTable::query()
			->setSelect(['ENTITY_ID'])
			->whereIn('ENTITY_ID', $memberIds)
			->where('ENTITY_TYPE', EntityType::MEMBER->value)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$existingIds[(int)$row['ENTITY_ID']] = true;
		}

		$missingIds = array_values(array_filter(
			$memberIds,
			static fn(int $id): bool => !isset($existingIds[$id]),
		));
		if (empty($missingIds))
		{
			return;
		}

		$createdByIdMap = [];
		$memberRows = \Bitrix\Sign\Internal\MemberTable::query()
			->setSelect(['ID', 'CREATED_BY_ID'])
			->whereIn('ID', $missingIds)
			->fetchAll()
		;
		foreach ($memberRows as $memberRow)
		{
			$createdByIdMap[(int)$memberRow['ID']] = (int)$memberRow['CREATED_BY_ID'];
		}

		$fallbackCreatedById = (int)CurrentUser::get()->getId();
		$rowsToAdd = [];
		foreach ($missingIds as $memberId)
		{
			$rowsToAdd[] = [
				'ENTITY_ID' => $memberId,
				'PARENT_ID' => $parentId,
				'ENTITY_TYPE' => EntityType::MEMBER->value,
				'DEPTH_LEVEL' => $depthLevel,
				'CREATED_BY_ID' => $createdByIdMap[$memberId] ?? $fallbackCreatedById,
			];
		}

		try
		{
			DocumentFolderRelationTable::addMulti($rowsToAdd, true);
		}
		catch (SqlQueryException $exception)
		{
			$createdIds = DocumentFolderRelationTable::query()
				->setSelect(['ENTITY_ID'])
				->whereIn('ENTITY_ID', $missingIds)
				->where('ENTITY_TYPE', EntityType::MEMBER->value)
				->fetchAll()
			;
			if (count($createdIds) !== count($missingIds))
			{
				throw $exception;
			}
		}
	}

	public function getDepthLevelByEntityIdAndType(int $entityId, EntityType $entityType): ?int
	{
		$row = DocumentFolderRelationTable::query()
			->setSelect(['DEPTH_LEVEL'])
			->where('ENTITY_ID', $entityId)
			->where('ENTITY_TYPE', $entityType->value)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['DEPTH_LEVEL'];
	}

	/**
	 * @param list<int> $parentIds
	 * @return list<int>
	 */
	public function getEntityIdsByParentIdsAndType(array $parentIds, EntityType $entityType): array
	{
		if (empty($parentIds))
		{
			return [];
		}

		$rows = DocumentFolderRelationTable::query()
			->setSelect(['ENTITY_ID'])
			->whereIn('PARENT_ID', $parentIds)
			->where('ENTITY_TYPE', $entityType->value)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ENTITY_ID'], $rows);
	}

	public function hasEntitiesByParentIdAndType(int $parentId, EntityType $entityType): bool
	{
		return DocumentFolderRelationTable::query()
			->setSelect(['ID'])
			->where('PARENT_ID', $parentId)
			->where('ENTITY_TYPE', $entityType->value)
			->setLimit(1)
			->fetch() !== false
		;
	}

	public function exceedsEntityLimitByParentIdAndType(
		int $parentId,
		EntityType $entityType,
		int $limit,
	): bool
	{
		$limit = max(0, $limit);
		$dbResult = DocumentFolderRelationTable::query()
			->setSelect(['ID'])
			->where('PARENT_ID', $parentId)
			->where('ENTITY_TYPE', $entityType->value)
			->setLimit($limit + 1)
			->exec()
		;

		$count = 0;
		while ($dbResult->fetch())
		{
			$count++;
			if ($count > $limit)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<int>
	 */
	public function getFolderIdsByDepthLevel(int $depthLevel): array
	{
		$rows = DocumentFolderRelationTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE', EntityType::FOLDER->value)
			->where('DEPTH_LEVEL', $depthLevel)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ENTITY_ID'], $rows);
	}

	public function deleteByEntityIdAndType(int $entityId, EntityType $entityType): Result
	{
		try
		{
			DocumentFolderRelationTable::deleteByFilter([
				'=ENTITY_ID' => $entityId,
				'=ENTITY_TYPE' => $entityType->value,
			]);
		}
		catch (ArgumentException $e)
		{
			return (new Result())->addError(new Error($e->getMessage()));
		}

		return new Result();
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function calculateDepthLevelForNewChild(int $parentId): int
	{
		if ($parentId <= 0)
		{
			return 0;
		}

		$depthLevel = $this->getDepthLevelByEntityIdAndType($parentId, EntityType::FOLDER);
		if ($depthLevel === null)
		{
			throw new ObjectNotFoundException("Folder with ID $parentId not found");
		}

		return $depthLevel + 1;
	}
}

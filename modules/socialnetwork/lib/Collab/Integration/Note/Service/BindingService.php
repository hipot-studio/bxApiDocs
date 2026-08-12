<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note\Service;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Collab\Internals\CollabNoteTable;

/**
 * Owns the collab <-> note collection binding stored in b_sonet_collab_note.
 *
 * Forward resolution (collab -> collectionId) is the primary read path used by
 * section resolution; create/delete are write helpers consumed by the lifecycle
 * handlers (Phase 2).
 */
class BindingService
{
	/**
	 * Resolves the bound note collection id for a collab, or null if none.
	 */
	public function findCollectionIdByCollab(int $collabId): ?int
	{
		if ($collabId <= 0)
		{
			return null;
		}

		$row = CollabNoteTable::query()
			->setSelect(['COLLECTION_ID'])
			->where('COLLAB_ID', $collabId)
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$collectionId = (int)$row['COLLECTION_ID'];

		return $collectionId > 0 ? $collectionId : null;
	}

	/**
	 * Returns the full binding row for a collab, or null if none.
	 *
	 * @return array{ID: int, COLLAB_ID: int, COLLECTION_ID: int, CREATED_BY: int}|null
	 */
	public function getBindingByCollab(int $collabId): ?array
	{
		if ($collabId <= 0)
		{
			return null;
		}

		$row = CollabNoteTable::query()
			->setSelect(['ID', 'COLLAB_ID', 'COLLECTION_ID', 'CREATED_BY'])
			->where('COLLAB_ID', $collabId)
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'COLLAB_ID' => (int)$row['COLLAB_ID'],
			'COLLECTION_ID' => (int)$row['COLLECTION_ID'],
			'CREATED_BY' => (int)$row['CREATED_BY'],
		];
	}

	/**
	 * Creates a binding between a collab and a note collection.
	 *
	 * Idempotency is enforced at the DB level by the unique index on COLLAB_ID.
	 */
	public function createBinding(int $collabId, int $collectionId, int $createdBy): Result
	{
		$result = new Result();

		if ($collabId <= 0 || $collectionId <= 0)
		{
			$result->addError(new \Bitrix\Main\Error('Invalid collab or collection id for binding'));

			return $result;
		}

		// The unique index on COLLAB_ID makes a duplicate insert a DB-level
		// DuplicateEntryException; honour the Result contract instead of letting it
		// propagate (idempotency guard — a binding already exists for this collab).
		try
		{
			$addResult = CollabNoteTable::add([
				'COLLAB_ID' => $collabId,
				'COLLECTION_ID' => $collectionId,
				'CREATED_BY' => $createdBy,
				'CREATED_AT' => new DateTime(),
			]);
		}
		catch (\Bitrix\Main\DB\SqlQueryException $e)
		{
			$result->addError(new \Bitrix\Main\Error('Binding for this collab already exists: ' . $e->getMessage()));

			return $result;
		}

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	/**
	 * Removes the binding for a collab. No-op if none exists.
	 */
	public function deleteByCollab(int $collabId): Result
	{
		$result = new Result();

		if ($collabId <= 0)
		{
			return $result;
		}

		CollabNoteTable::deleteByFilter(['=COLLAB_ID' => $collabId]);

		return $result;
	}
}

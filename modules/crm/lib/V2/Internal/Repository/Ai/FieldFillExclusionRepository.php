<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Ai;

use Bitrix\Crm\V2\Internal\Model\Ai\FieldFillExclusionTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Result;
use Bitrix\Main\UserFieldTable;

/**
 * The single point {@see \Bitrix\Crm\V2\Internal\Model\Ai\FieldFillExclusionTable} is read and written
 * through.
 *
 * Answering the policy is collection-wide on purpose: the caller hands over every user field id of one
 * entity and gets back the excluded ones in a single query. {@see self::exists()} asks about one field
 * for a write path only - so that it can leave alone a state that is already the wanted one.
 *
 * Both writes state the wanted state rather than a transition, and repeating either one changes
 * nothing - the caller does not have to know whether the row is already there. A row two parallel
 * writes both mean to insert is the wanted state as well, so the unique index rejecting the second
 * insert is not a failure of that write; anything else the storage rejects is, and travels back in the
 * returned {@see \Bitrix\Main\Result}.
 *
 * @internal
 */
class FieldFillExclusionRepository
{
	/**
	 * @param int[] $userFieldIds
	 * @return int[]
	 */
	public function findExcludedUserFieldIds(array $userFieldIds): array
	{
		if ($userFieldIds === [] || !$this->hasStorage())
		{
			return [];
		}

		$rows = FieldFillExclusionTable::query()
			->setSelect(['USER_FIELD_ID'])
			->whereIn('USER_FIELD_ID', array_unique($userFieldIds))
			->fetchAll()
		;

		$excludedIds = [];
		foreach ($rows as $row)
		{
			$excludedIds[] = (int)$row['USER_FIELD_ID'];
		}

		return $excludedIds;
	}

	public function add(int $userFieldId): Result
	{
		$result = new Result();

		try
		{
			$addResult = FieldFillExclusionTable::add(['USER_FIELD_ID' => $userFieldId]);
		}
		catch (DuplicateEntryException)
		{
			return $result;
		}

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	/**
	 * A row that is not there is the wanted state too, so the caller may hand over any user field id.
	 */
	public function delete(int $userFieldId): Result
	{
		FieldFillExclusionTable::deleteByFilter(['=USER_FIELD_ID' => $userFieldId]);

		return new Result();
	}

	/**
	 * Rows the storage keeps for user fields the portal no longer has. The row names its field by id
	 * alone, and the user fields of a deleted entity are removed with plain queries rather than one at a
	 * time, so nothing but the field itself being gone is left to find such a row by.
	 */
	public function deleteOrphaned(): Result
	{
		if (!$this->hasStorage())
		{
			return new Result();
		}

		$rows = FieldFillExclusionTable::query()
			->setSelect(['USER_FIELD_ID'])
			->whereNotIn('USER_FIELD_ID', UserFieldTable::query()->setSelect(['ID']))
			->fetchAll()
		;

		$orphanedUserFieldIds = [];
		foreach ($rows as $row)
		{
			$orphanedUserFieldIds[] = (int)$row['USER_FIELD_ID'];
		}

		if ($orphanedUserFieldIds !== [])
		{
			FieldFillExclusionTable::deleteByFilter(['@USER_FIELD_ID' => $orphanedUserFieldIds]);
		}

		return new Result();
	}

	public function exists(int $userFieldId): bool
	{
		if (!$this->hasStorage())
		{
			return false;
		}

		return (bool)FieldFillExclusionTable::query()
			->setSelect(['ID'])
			->where('USER_FIELD_ID', $userFieldId)
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * Whether the storage exists at all. In the cloud the files of a module and its database are updated
	 * at separate times, so this code can run on a portal the table has not been created on yet. A read
	 * and a cleanup then answer the way an empty storage would - nothing is forbidden, nothing is left to
	 * drop - because neither of them is what its caller came for: the policy must not be able to break
	 * the rendering of a CRM card or the deletion of an entity type. A write is left unguarded on
	 * purpose - a policy that cannot be stored has to be reported to the one who set it rather than
	 * answered with success.
	 */
	private function hasStorage(): bool
	{
		return Application::getConnection()->isTableExists(FieldFillExclusionTable::getTableName());
	}
}

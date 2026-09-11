<?php

namespace Bitrix\Sign\Repository\SignersList;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Item\SignersListUser;
use Bitrix\Sign\Item\SignersListUserCollection;

class SignersListUserRepository
{
	public function list(
		ConditionTree $filter,
		int $limit = 0,
		int $offset = 0,
	): SignersListUserCollection
	{
		$limit = max(0, $limit);
		$offset = max(0, $offset);

		$query = $this->prepareUserListQuery($filter, $limit, $offset);
		/** @var Internal\SignersList\SignersListUserCollection $models */
		$models = $query->fetchCollection();

		return $this->extractUserItemCollectionFromModelCollection($models);
	}

	/**
	 * Identifiers of the list composition, read row by row without building an object per
	 * participant: the callers that only need who is in the group must not pay for the whole
	 * composition materialized twice.
	 *
	 * @param int $limit zero means the whole composition
	 *
	 * @return list<int>
	 */
	public function listUserIds(int $listId, int $limit = 0): array
	{
		$rows = Internal\SignersList\SignersListUserTable::query()
			->setSelect(['USER_ID'])
			->where('LIST_ID', $listId)
			->setLimit(max(0, $limit))
			->exec()
		;

		$userIds = [];
		while ($row = $rows->fetch())
		{
			$userIds[] = (int)$row['USER_ID'];
		}

		return $userIds;
	}

	public function count(ConditionTree $filter): int
	{
		return $this->prepareUserListQuery($filter)->queryCountTotal();
	}

	/**
	 * Tells which of the given lists have signers at all, without counting them: the primary key of
	 * the table starts with LIST_ID, so the distinct identifiers are taken from the index and the
	 * composition rows are never read.
	 *
	 * @param int[] $listIds
	 *
	 * @return list<int> identifiers of the given lists with at least one signer
	 */
	public function listNonEmptyListIds(array $listIds): array
	{
		$listIds = array_unique(array_map('intval', $listIds));

		if (!$listIds)
		{
			return [];
		}

		$rows = Internal\SignersList\SignersListUserTable::query()
			->setSelect(['LIST_ID'])
			->setDistinct()
			->whereIn('LIST_ID', $listIds)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['LIST_ID'], $rows);
	}

	public function add(SignersListUserCollection $signers): Result
	{
		if ($signers->count() === 0)
		{
			return new Result();
		}

		return $this->extractUserItemModelCollectionFromItemCollection($signers)->save();
	}

	/**
	 * @param int[] $userIds
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function deleteSignersFromList(int $listId, array $userIds): Result
	{
		if (!$userIds)
		{
			return new Result();
		}

		Internal\SignersList\SignersListUserTable::deleteByFilter([
			'=LIST_ID' => $listId,
			'=USER_ID' => $userIds,
		]);

		return new Result();
	}

	/**
	 * @param int[] $listIds
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function deleteSignerFromLists(int $userId, array $listIds): Result
	{
		if (!$listIds)
		{
			return new Result();
		}

		Internal\SignersList\SignersListUserTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=LIST_ID' => $listIds,
		]);

		return new Result();
	}

	public function listNotEmptyListIds(array $listIds): array
	{
		if (!$listIds)
		{
			return [];
		}

		return Internal\SignersList\SignersListUserTable::query()
			->setSelect(['LIST_ID'])
			->whereIn('LIST_ID', $listIds)
			->setDistinct()
			->fetchAll();
	}

	private function prepareUserListQuery(ConditionTree $filter, int $limit = 10, int $offset = 0): Query
	{
		return Internal\SignersList\SignersListUserTable::query()
			->setSelect(['*'])
			->setLimit($limit)
			->setOffset($offset)
			->where($filter)
		;
	}

	private function extractUserItemCollectionFromModelCollection(Internal\SignersList\SignersListUserCollection $models): SignersListUserCollection
	{
		$items = array_map($this->extractUserItemFromModel(...), $models->getAll());

		return new SignersListUserCollection(...$items);
	}

	private function extractUserModelFromItem(SignersListUser $item): Internal\SignersList\SignersListUser
	{
		return $this->getFilledUserModelFromItem($item, Internal\SignersList\SignersListUserTable::createObject(false));
	}


	private function getFilledUserModelFromItem(SignersListUser $item, Internal\SignersList\SignersListUser $model): Internal\SignersList\SignersListUser
	{
		return $model
			->setListId($item->listId)
			->setUserId($item->userId)
			->setCreatedById($item->createdById)
			->setDateCreate($item->dateCreate)
		;
	}

	private function extractUserItemFromModel(Internal\SignersList\SignersListUser $model): SignersListUser
	{
		return new SignersListUser(
			listId: $model->getListId(),
			userId: $model->getUserId(),
			createdById: $model->getCreatedById(),
			dateCreate: \Bitrix\Sign\Type\DateTime::createFromMainDateTime($model->getDateCreate()),
		);
	}

	private function extractUserItemModelCollectionFromItemCollection(SignersListUserCollection $collection): Internal\SignersList\SignersListUserCollection
	{
		$models = new Internal\SignersList\SignersListUserCollection();
		foreach ($collection as $item)
		{
			$models->add($this->extractUserModelFromItem($item));
		}
		return $models;
	}
}
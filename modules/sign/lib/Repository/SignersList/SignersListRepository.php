<?php

namespace Bitrix\Sign\Repository\SignersList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Item\SignersList;
use Bitrix\Sign\Item\SignersListCollection;
use Bitrix\Sign\Item\SignersListUser;
use Bitrix\Sign\Item\SignersListUserCollection;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\SignersList\SortField;
use Bitrix\Sign\Type\SignersList\UserOptionCode;

class SignersListRepository
{
	public function add(SignersList $list): AddResult
	{
		$model = $this->extractModelFromItem($list);
		$result = $model->save();

		if (!$result->isSuccess())
		{
			return $result;
		}

		$list->id = $result->getId();

		return $result;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function update(SignersList $list): Result|AddResult
	{
		$model = Internal\SignersList\SignersListTable::getByPrimary($list->id)->fetchObject();

		if (!$model)
		{
			return (new Result())->addError(
				new \Bitrix\Main\Error('List not found'),
			);
		}

		return $this->getFilledModelFromItem($list, $model)->save();
	}

	/**
	 * @param int[] $listIds
	 * @throws SystemException
	 */
	public function updateModificationTime(array $listIds, int $modifyBy, DateTime $dateModify): Result
	{
		if (!$listIds)
		{
			return new Result();
		}

		Internal\SignersList\SignersListTable::updateByFilter(
			[
				'=ID' => $listIds,
			],
			[
				'DATE_MODIFY' => $dateModify,
				'MODIFIED_BY_ID' => $modifyBy,
			],
		);

		return new Result();
	}

	public function list(
		ConditionTree $filter,
		int $limit = 0,
		int $offset = 0,
		?SortField $sortField = null,
		Order $sortDirection = Order::Desc,
		?int $pinnedForUserId = null,
	): SignersListCollection
	{
		$limit = max(0, $limit);
		$offset = max(0, $offset);

		$query = $this->prepareListQuery($filter, $limit, $offset, $sortField, $sortDirection, $pinnedForUserId);
		/** @var Internal\SignersList\SignersListCollection $models */
		$models = $query->fetchCollection();

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function getById(int $listId): ?SignersList
	{
		if ($listId < 1)
		{
			return null;
		}

		$model = Internal\SignersList\SignersListTable::getByPrimary($listId)->fetchObject();

		if ($model === null)
		{
			return null;
		}

		return $this->extractItemFromModel($model);
	}

	public function count(ConditionTree $filter): int
	{
		return $this->prepareListQuery($filter)->queryCountTotal();
	}

	public function delete(int $listId): Result
	{
		return Internal\SignersList\SignersListTable::delete($listId);
	}

	private function prepareListQuery(
		ConditionTree $filter,
		int $limit = 10,
		int $offset = 0,
		?SortField $sortField = null,
		Order $sortDirection = Order::Desc,
		?int $pinnedForUserId = null,
	): Query
	{
		$query = Internal\SignersList\SignersListTable::query()
			->setSelect(['*'])
			->setLimit($limit)
			->setOffset($offset)
			->where($filter)
		;

		// NORMATIVE ALG-01: pinned rows first, then the requested column, then the stable ID key
		if ($pinnedForUserId !== null)
		{
			$this->applyPinnedPriorityOrder($query, $pinnedForUserId);
		}

		if ($sortField === SortField::DateModify)
		{
			$this->applyModificationDateOrder($query, $sortDirection);
		}
		elseif ($sortField !== null)
		{
			$query->addOrder($sortField->value, $sortDirection->value);
		}

		// addOrder() is keyed by field name, so the stable key must be skipped when ID is
		// already the requested column - otherwise it would overwrite its direction
		if ($sortField !== SortField::Id)
		{
			$query->addOrder('ID', 'DESC');
		}

		return $query;
	}

	/**
	 * Orders by the modification date the way the grid shows it: a list that was never modified
	 * displays its creation date, so ordering by the raw column would put such lists into a
	 * separate NULL block and the visible order would contradict the visible dates.
	 */
	private function applyModificationDateOrder(Query $query, Order $sortDirection): void
	{
		$query
			->registerRuntimeField(
				'SORT_DATE_MODIFY',
				new ExpressionField(
					'SORT_DATE_MODIFY',
					'COALESCE(%s, %s)',
					['DATE_MODIFY', 'DATE_CREATE'],
				),
			)
			->addOrder('SORT_DATE_MODIFY', $sortDirection->value)
		;
	}

	/**
	 * Left-joins the personal options of the given user and lifts the lists pinned by them
	 * to the top. The composite primary key of the options table keeps the join free of
	 * row duplicates, so the selection itself stays unchanged.
	 */
	private function applyPinnedPriorityOrder(Query $query, int $pinnedForUserId): void
	{
		$query
			->registerRuntimeField(
				'PIN',
				new Reference(
					'PIN',
					Internal\SignersList\SignersListUserOptionTable::class,
					Join::on('this.ID', 'ref.LIST_ID')
						->where('ref.USER_ID', $pinnedForUserId)
						->where('ref.OPTION_CODE', UserOptionCode::Pinned->value),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->registerRuntimeField(
				'PINNED_PRIORITY',
				new ExpressionField(
					'PINNED_PRIORITY',
					'CASE WHEN %s IS NULL THEN 0 ELSE 1 END',
					'PIN.LIST_ID',
				),
			)
			->addOrder('PINNED_PRIORITY', 'DESC')
		;
	}

	private function extractItemCollectionFromModelCollection(Internal\SignersList\SignersListCollection $models): SignersListCollection
	{
		$items = array_map($this->extractItemFromModel(...), $models->getAll());

		return new SignersListCollection(...$items);
	}

	private function extractModelFromItem(SignersList $item): Internal\SignersList\SignersList
	{
		return $this->getFilledModelFromItem($item, Internal\SignersList\SignersListTable::createObject(false));
	}

	private function getFilledModelFromItem(SignersList $item, Internal\SignersList\SignersList $model): Internal\SignersList\SignersList
	{
		return $model
			->setTitle($item->title)
			->setDateCreate($item->dateCreate)
			->setDateModify($item->dateModify)
			->setCreatedById($item->createdById)
			->setModifiedById($item->modifiedById)
		;
	}

	private function extractItemFromModel(Internal\SignersList\SignersList $model): SignersList
	{
		return new SignersList(
			id: $model->getId(),
			title: $model->getTitle(),
			createdById: $model->getCreatedById(),
			modifiedById: $model->getModifiedById(),
			dateCreate: DateTime::createFromMainDateTime($model->getDateCreate()),
			dateModify: DateTime::createFromMainDateTimeOrNull($model->getDateModify()),
		);
	}
}

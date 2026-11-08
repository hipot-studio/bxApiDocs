<?php

namespace Bitrix\HumanResources\Repository\HcmLink;

use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Model;
use Bitrix\HumanResources\Model\HcmLink\ExclusionTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

class ExclusionRepository
{
	public const USER_ID_CHUNK_SIZE = 300;

	private const USER_SEARCH_FIELDS = ['USER.NAME', 'USER.LAST_NAME', 'USER.SECOND_NAME'];

	/**
	 * @param array<int> $userIds
	 *
	 * @return array<int> those of the passed users who are excluded in the company
	 */
	public function filterExcludedUserIds(int $companyId, array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$excludedUserIds = [];
		foreach (array_chunk($userIds, self::USER_ID_CHUNK_SIZE) as $userIdsChunk)
		{
			$rows = ExclusionTable::query()
				->setSelect(['USER_ID'])
				->where('COMPANY_ID', $companyId)
				->whereIn('USER_ID', $userIdsChunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$excludedUserIds[] = (int)$row['USER_ID'];
			}
		}

		return $excludedUserIds;
	}

	/**
	 * Already excluded users are skipped instead of breaking the whole set on the unique index.
	 *
	 * @param array<int> $userIds
	 *
	 * @return int count of created records
	 */
	public function addUserIds(int $companyId, array $userIds, int $createdBy): int
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return 0;
		}

		$userIdsToAdd = array_values(
			array_diff($userIds, $this->filterExcludedUserIds($companyId, $userIds)),
		);
		if ($userIdsToAdd === [])
		{
			return 0;
		}

		$createdAt = new DateTime();
		foreach (array_chunk($userIdsToAdd, self::USER_ID_CHUNK_SIZE) as $userIdsChunk)
		{
			$rows = [];
			foreach ($userIdsChunk as $userId)
			{
				$rows[] = [
					'COMPANY_ID' => $companyId,
					'USER_ID' => $userId,
					'CREATED_BY' => $createdBy,
					'CREATED_AT' => $createdAt,
				];
			}

			// Events are skipped on purpose: with them the ORM cannot report the identifiers it has to
			// pass to the handlers and falls back to a query per row, which is exactly what the chunk
			// is here to avoid. The table is internal to the module and nobody subscribes to it.
			$result = ExclusionTable::addMulti($rows, true);
			if (!$result->isSuccess())
			{
				throw (new CreationFailedException())->setErrors($result->getErrorCollection());
			}
		}

		return count($userIdsToAdd);
	}

	/**
	 * @return int count of removed records
	 */
	public function deleteByUserId(int $companyId, int $userId): int
	{
		return $this->deleteByUserIds($companyId, [$userId]);
	}

	/**
	 * @param array<int> $userIds
	 *
	 * @return int count of removed records
	 */
	public function deleteByUserIds(int $companyId, array $userIds): int
	{
		$excludedUserIds = $this->filterExcludedUserIds($companyId, $userIds);
		if ($excludedUserIds === [])
		{
			return 0;
		}

		foreach (array_chunk($excludedUserIds, self::USER_ID_CHUNK_SIZE) as $userIdsChunk)
		{
			ExclusionTable::deleteByFilter(
				Query::filter()
					->where('COMPANY_ID', $companyId)
					->whereIn('USER_ID', $userIdsChunk),
			);
		}

		return count($excludedUserIds);
	}

	public function deleteByCompanyId(int $companyId): void
	{
		ExclusionTable::deleteByFilter(Query::filter()->where('COMPANY_ID', $companyId));
	}

	/**
	 * The user is joined from outside: an exclusion of a user removed from the portal has to stay
	 * on the list, otherwise it becomes impossible to remove through the interface.
	 *
	 * @return array<Item\HcmLink\Exclusion>
	 */
	public function getListByCompanyId(
		int $companyId,
		?ConditionTree $filter = null,
		?string $searchName = null,
		int $limit = 20,
		int $offset = 0,
	): array
	{
		$models = $this->createCompanyQuery($companyId, $filter, $searchName)
			->setSelect(['*'])
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
			->fetchCollection()
		;

		$items = [];
		foreach ($models as $model)
		{
			$items[] = $this->getItemFromModel($model);
		}

		return $items;
	}

	public function countByCompanyId(
		int $companyId,
		?ConditionTree $filter = null,
		?string $searchName = null,
	): int
	{
		return (int)$this->createCompanyQuery($companyId, $filter, $searchName)->queryCountTotal();
	}

	private function createCompanyQuery(int $companyId, ?ConditionTree $filter, ?string $searchName): Query
	{
		$query = ExclusionTable::query()
			->registerRuntimeField(
				new Reference(
					'USER',
					UserTable::class,
					Join::on('this.USER_ID', 'ref.ID'),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->where('COMPANY_ID', $companyId)
		;

		if ($filter !== null)
		{
			$query->where($filter);
		}

		$searchName = trim((string)$searchName);
		if ($searchName !== '')
		{
			$query->where($this->createUserNameFilter($searchName));
		}

		return $query;
	}

	private function createUserNameFilter(string $searchName): ConditionTree
	{
		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach (self::USER_SEARCH_FIELDS as $field)
		{
			$filter->whereLike($field, "%{$searchName}%");
		}

		return $filter;
	}

	/**
	 * @param array<int> $userIds
	 *
	 * @return array<int> unique positive identifiers
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	private function getItemFromModel(Model\HcmLink\Exclusion $model): Item\HcmLink\Exclusion
	{
		return new Item\HcmLink\Exclusion(
			companyId: $model->getCompanyId(),
			userId: $model->getUserId(),
			createdBy: $model->getCreatedBy(),
			createdAt: $model->getCreatedAt(),
			id: $model->getId(),
		);
	}
}

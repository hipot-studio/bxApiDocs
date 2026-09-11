<?php

namespace Bitrix\Sign\Repository\SignersList;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Sign\Type\SignersList\UserOptionCode;

class SignersListUserOptionRepository
{
	public function add(int $listId, int $userId, UserOptionCode $code): Result
	{
		return Internal\SignersList\SignersListUserOptionTable::addInsertIgnore([
			'LIST_ID' => $listId,
			'USER_ID' => $userId,
			'OPTION_CODE' => $code->value,
			'DATE_CREATE' => new DateTime(),
		]);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function delete(int $listId, int $userId, UserOptionCode $code): void
	{
		Internal\SignersList\SignersListUserOptionTable::deleteByFilter(
			(new ConditionTree())
				->where('LIST_ID', $listId)
				->where('USER_ID', $userId)
				->where('OPTION_CODE', $code->value)
		);
	}

	/**
	 * @param int[] $listIds
	 *
	 * @return int[] identifiers of the given lists which have the option set for the user
	 */
	public function listListIdsWithOption(int $userId, array $listIds, UserOptionCode $code): array
	{
		if (!$listIds)
		{
			return [];
		}

		$rows = Internal\SignersList\SignersListUserOptionTable::query()
			->addSelect('LIST_ID')
			->where('USER_ID', $userId)
			->where('OPTION_CODE', $code->value)
			->whereIn('LIST_ID', $listIds)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['LIST_ID'], $rows);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function deleteAllByUser(int $userId): void
	{
		Internal\SignersList\SignersListUserOptionTable::deleteByFilter(
			(new ConditionTree())->where('USER_ID', $userId)
		);
	}
}

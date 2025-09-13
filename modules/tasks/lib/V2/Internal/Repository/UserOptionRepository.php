<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\UserOptionTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Exception\Task\UserOptionException;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\UserOptionMapper;

class UserOptionRepository implements UserOptionRepositoryInterface
{
	public function __construct(
		private readonly UserOptionMapper $userOptionMapper,
	)
	{

	}

	public function get(int $taskId, int $userId): Entity\Task\UserOptionCollection
	{
		$data = UserOptionTable::query()
			->setSelect(['ID', 'OPTION_CODE'])
			->where('TASK_ID', $taskId)
			->where('USER_ID', $userId)
			->exec()
			->fetchAll();

		foreach ($data as &$row)
		{
			$row['ID'] = (int)$row['ID'];
			$row['OPTION_CODE'] = (int)$row['OPTION_CODE'];
			$row['TASK_ID'] = $taskId;
			$row['USER_ID'] = $userId;
		}

		unset($row);

		return $this->userOptionMapper->mapToCollection($data);
	}

	public function isSet(int $code, int $taskId, int $userId): bool
	{
		$options = $this->get($taskId, $userId);

		return $options->findOne(['code' => $code]) !== null;
	}

	public function add(Entity\Task\UserOption $userOption): void
	{
		$data = $this->userOptionMapper->mapFromEntity($userOption);

		try
		{
			$result = UserOptionTable::add($data);
		}
		catch (DuplicateEntryException)
		{
			return;
		}

		if (!$result->isSuccess())
		{
			throw new UserOptionException($result->getError()?->getMessage());
		}
	}

	public function delete(array $codes = [], int $taskId = 0, int $userId = 0): void
	{
		$filter = [];

		Collection::normalizeArrayValuesByInt($codes, false);
		if (!empty($codes))
		{
			$filter['@OPTION_CODE'] = $codes;
		}

		if ($taskId > 0)
		{
			$filter['=TASK_ID'] = $taskId;
		}

		if ($userId > 0)
		{
			$filter['=USER_ID'] = $userId;
		}

		if (empty($filter))
		{
			return;
		}

		try
		{
			UserOptionTable::deleteByFilter($filter);
		}
		catch (SqlQueryException $e)
		{
			throw new UserOptionException($e->getMessage());
		}
	}
}
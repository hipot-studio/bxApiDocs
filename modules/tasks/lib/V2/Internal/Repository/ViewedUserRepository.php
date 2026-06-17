<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\DB\Order;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\ViewedTable;
use Bitrix\Tasks\V2\Internal\Entity\Task\View\ViewedUserCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\View\ViewedUserMapper;

class ViewedUserRepository implements ViewedUserRepositoryInterface
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly ViewedUserMapper $viewedUserMapper,
	)
	{

	}

	public function tail(int $taskId, int $offset, int $limit): ViewedUserCollection
	{
		$rows = ViewedTable::query()
			->setSelect(['TASK_ID', 'USER_ID', 'VIEWED_DATE'])
			->where('TASK_ID', $taskId)
			->setOrder(['VIEWED_DATE' => Order::Desc->value])
			->setOffset($offset)
			->setLimit($limit)
			->exec()
			->fetchAll();

		if (empty($rows))
		{
			return new ViewedUserCollection();
		}

		$userIds = array_column($rows, 'USER_ID');

		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return new ViewedUserCollection();
		}

		$users = $this->userRepository->getByIds($userIds);

		$dates = [];
		foreach ($rows as $row)
		{
			$dates[(int)$row['USER_ID']] = $row['VIEWED_DATE'];
		}

		return $this->viewedUserMapper->mapToCollection($users, $dates);
	}

	public function getCount(int $taskId): int
	{
		$row = ViewedTable::query()
			->setSelect([Query::expr('CNT')->count('*')])
			->where('TASK_ID', $taskId)
			->exec()
			->fetch();

		if (!is_array($row))
		{
			return 0;
		}

		return (int)($row['CNT'] ?? 0);
	}
}

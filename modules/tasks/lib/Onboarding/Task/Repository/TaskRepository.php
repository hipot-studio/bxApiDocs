<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Task\Repository;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Onboarding\Task\TaskRepositoryInterface;

class TaskRepository implements TaskRepositoryInterface
{
	private static array $count = [];

	protected ValidationService $validationService;

	public function __construct()
	{
		$this->init();
	}

	public function getCount(int $userId): int
	{
		if (isset(static::$count[$userId]))
		{
			return static::$count[$userId];
		}

		if ($userId <= 0)
		{
			return 0;
		}

		$statusFilter = Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->whereIn('STATUS', Status::getInWorkStatuses());

		$row = TaskTable::query()
			->setSelect([Query::expr('COUNT')->count('ID')])
			->where('CREATED_BY', $userId)
			->where('RESPONSIBLE_ID', $userId)
			->where($statusFilter)
			->exec()
			->fetch();

		$count = (int)($row['COUNT'] ?? 0);

		static::$count[$userId] = $count;

		return static::$count[$userId];
	}

	protected function init(): void
	{
		$this->validationService = ServiceLocator::getInstance()->get('main.validation.service');
	}
}
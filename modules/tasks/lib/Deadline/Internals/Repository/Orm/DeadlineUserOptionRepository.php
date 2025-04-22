<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Internals\Repository\Orm;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\Deadline\Internals\Model\DeadlineUserOptionTable;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\Deadline\Internals\Repository\Orm\Mapper\DeadlineUserOptionMapper;

class DeadlineUserOptionRepository implements DeadlineUserOptionRepositoryInterface
{
	public function __construct(
		private readonly DeadlineUserOptionMapper $mapper,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByUserId(int $userId): DeadlineUserOption
	{
		$ormModel =
			DeadlineUserOptionTable::query()
				->setSelect([
					'ID',
					'USER_ID',
					'DEFAULT_DEADLINE',
					'IS_EXACT_DEADLINE_TIME',
					'SKIP_NOTIFICATION_PERIOD',
					'SKIP_NOTIFICATION_START_DATE',
				])
				->where('USER_ID', $userId)
				->exec()
				->fetchObject()
		;

		return
			$ormModel
				? $this->mapper->convertFromOrm($ormModel)
				: new DeadlineUserOption($userId)
		;
	}

	public function save(DeadlineUserOption $entity): void
	{
		$insertFields = [
			'USER_ID' => $entity->userId,
			'DEFAULT_DEADLINE' => $entity->defaultDeadlineInSeconds,
			'IS_EXACT_DEADLINE_TIME' => $entity->isExactDeadlineTime,
			'SKIP_NOTIFICATION_PERIOD' => $entity->skipNotificationPeriod->value,
			'SKIP_NOTIFICATION_START_DATE' => $entity->skipNotificationStartDate,
		];

		$updateFields = [
			'DEFAULT_DEADLINE' => $entity->defaultDeadlineInSeconds,
			'IS_EXACT_DEADLINE_TIME' => $entity->isExactDeadlineTime,
			'SKIP_NOTIFICATION_PERIOD' => $entity->skipNotificationPeriod->value,
			'SKIP_NOTIFICATION_START_DATE' => $entity->skipNotificationStartDate,
		];

		$uniqueFields = ['USER_ID'];

		DeadlineUserOptionTable::merge($insertFields, $updateFields, $uniqueFields);
	}
}

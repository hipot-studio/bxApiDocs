<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Internals\Repository\Orm\Mapper;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\Deadline\Internals\Model\DeadlineUserOptionTable;
use Bitrix\Tasks\Deadline\Internals\Model\EO_DeadlineUserOption;
use Bitrix\Tasks\Deadline\SkipNotificationPeriod;

class DeadlineUserOptionMapper
{
	public function convertFromOrm(EO_DeadlineUserOption $ormModel): DeadlineUserOption
	{
		$skipNotificationPeriod =
			SkipNotificationPeriod::tryFrom($ormModel->getSkipNotificationPeriod())
			?? SkipNotificationPeriod::DEFAULT
		;

		$deadlineUserOption = new DeadlineUserOption(
			$ormModel->getUserId(),
			$ormModel->getDefaultDeadline(),
			$ormModel->getIsExactDeadlineTime(),
			$skipNotificationPeriod,
			$ormModel->getSkipNotificationStartDate(),
		);

		$deadlineUserOption->id = $ormModel->getId();

		return $deadlineUserOption;
	}

	/**
	 * @param DeadlineUserOption $entity
	 *
	 * @return EO_DeadlineUserOption
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function convertToOrm(DeadlineUserOption $entity): EO_DeadlineUserOption
	{
		$ormModel =
			$entity->getId()
				? EO_DeadlineUserOption::wakeUp($entity->id)
				: DeadlineUserOptionTable::createObject()
		;

		$ormModel
			->setUserId($entity->userId)
			->setDefaultDeadline($entity->defaultDeadlineInSeconds)
			->setIsExactDeadlineTime($entity->isExactDeadlineTime)
			->setSkipNotificationPeriod($entity->skipNotificationPeriod->value)
			->setSkipNotificationStartDate($entity->skipNotificationStartDate)
		;

		return $ormModel;
	}
}

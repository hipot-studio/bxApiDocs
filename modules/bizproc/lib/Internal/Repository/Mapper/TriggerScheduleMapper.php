<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Trigger\ScheduledData;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerSchedule;
use Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule;
use Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable;

class TriggerScheduleMapper
{
	public function convertFromOrm(EO_TriggerSchedule $orm): TriggerSchedule
	{
		return (new TriggerSchedule())
			->setId($orm->getId())
			->setTemplateId($orm->getTemplateId())
			->setTriggerName($orm->getTriggerName())
			->setScheduleType($orm->getScheduleType())
			->setScheduleData(ScheduledData::fromArray($orm->getScheduleData()))
			->setNextRunAt($orm->getNextRunAt())
			->setLastRunAt($orm->getLastRunAt())
		;
	}

	public function convertToOrm(TriggerSchedule $entity): EO_TriggerSchedule
	{
		$orm = $entity->getId()
			? EO_TriggerSchedule::wakeUp($entity->getId())
			: TriggerScheduleTable::createObject();

		$orm
			->setTemplateId($entity->getTemplateId())
			->setTriggerName($entity->getTriggerName())
			->setScheduleType($entity->getScheduleType())
			->setScheduleData($entity->getScheduleData()->toArray())
			->setNextRunAt($entity->getNextRunAt())
			->setLastRunAt($entity->getLastRunAt())
		;

		return $orm;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\TriggerScheduleRepository;

use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerSchedule;
use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerScheduleCollection;
use Bitrix\Bizproc\Internal\Model\Trigger\TriggerScheduleTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\TriggerScheduleMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class TriggerScheduleRepository
{
	public function __construct(private readonly TriggerScheduleMapper $mapper = new TriggerScheduleMapper())
	{
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(TriggerSchedule $schedule): TriggerSchedule
	{
		try
		{
			$result = $this->mapper->convertToOrm($schedule)->save();
		}
		catch (\Exception $exception)
		{
			throw new PersistenceException($exception->getMessage(), $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save trigger schedule: ' . implode('; ', $result->getErrorMessages())
			);
		}

		if ($schedule->getId() === null)
		{
			$schedule->setId((int)$result->getId());
		}

		return $schedule;
	}

	/**
	 * @throws ArgumentException
	 */
	public function deleteByTemplate(int $templateId, array $triggerNames = []): void
	{
		if ($templateId <= 0)
		{
			return;
		}

		$filter = ['=TEMPLATE_ID' => $templateId];
		$triggerNames = array_values(array_unique(array_filter($triggerNames, 'is_string')));

		if ($triggerNames)
		{
			$filter['!@TRIGGER_NAME'] = $triggerNames;
		}

		TriggerScheduleTable::deleteByFilter($filter);
	}

	/**
	 * @param int $templateId
	 *
	 * @return TriggerScheduleCollection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByTemplate(int $templateId): TriggerScheduleCollection
	{
		$collection = new TriggerScheduleCollection();
		if ($templateId <= 0)
		{
			return $collection;
		}

		$ormCollection = TriggerScheduleTable::query()
			->setSelect(['*'])
			->where('TEMPLATE_ID', $templateId)
			->fetchCollection()
		;

		if (!$ormCollection || $ormCollection->isEmpty())
		{
			return $collection;
		}

		foreach ($ormCollection as $ormItem)
		{
			$collection->add($this->mapper->convertFromOrm($ormItem));
		}

		return $collection;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getDueSchedules(DateTime $now, int $limit): TriggerScheduleCollection
	{
		$query = TriggerScheduleTable::query()
			->setSelect(['*'])
			->whereNotNull('NEXT_RUN_AT')
			->where('NEXT_RUN_AT', '<=', $now)
			->setOrder([
				'NEXT_RUN_AT' => 'ASC',
				'ID' => 'ASC',
			])
		;

		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		$collection = new TriggerScheduleCollection();
		$ormCollection = $query->fetchCollection();
		if (!$ormCollection || $ormCollection->isEmpty())
		{
			return $collection;
		}

		foreach ($ormCollection as $ormItem)
		{
			$collection->add($this->mapper->convertFromOrm($ormItem));
		}

		return $collection;
	}

	/**
	 * Actualizes schedule by id and expected scheduled date
	 *
	 * @param int $scheduleId
	 * @param DateTime $scheduledAt
	 * @param DateTime $now
	 * @param DateTime|null $nextRunAt
	 *
	 * @return bool
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws SqlQueryException
	 */
	public function actualizeSchedule(
		int $scheduleId,
		DateTime $scheduledAt,
		DateTime $now,
		?DateTime $nextRunAt,
	): bool
	{
		$affected = TriggerScheduleTable::updateByFilter(
			[
				'=ID' => $scheduleId,
				'=NEXT_RUN_AT' => $scheduledAt,
			],
			[
				'LAST_RUN_AT' => $now,
				'NEXT_RUN_AT' => $nextRunAt,
				'UPDATED_AT' => $now,
			]
		);

		return $affected > 0;
	}


	/**
	 * Fetches nearest next run date among all schedules.
	 *
	 * @return DateTime|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getNearestNextRunAt(): ?DateTime
	{
		$row = TriggerScheduleTable::query()
			->setSelect(['NEXT_RUN_AT'])
			->whereNotNull('NEXT_RUN_AT')
			->setOrder([
				'NEXT_RUN_AT' => 'ASC',
			])
			->setLimit(1)
			->fetch()
		;

		$nextRunAt = $row['NEXT_RUN_AT'] ?? null;

		return $nextRunAt instanceof DateTime ? $nextRunAt : null;
	}
}

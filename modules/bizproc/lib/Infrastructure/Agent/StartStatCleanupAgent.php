<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Internal\Config\StartListOrder;
use Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable;
use Bitrix\Bizproc\Internal\Service\StartStat\PortalDate;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;

/**
 * Stepper deletes the daily buckets of successful manual starts left behind the retention window. Runs
 * regardless of the order flag: a portal that never switched the new order on still has to lose the
 * statistics it has already collected.
 */
class StartStatCleanupAgent extends Stepper
{
	private const RECORD_LIMIT = 100;
	private const TEMPLATE_IDS_BATCH_SIZE = 300;

	protected static $moduleId = 'bizproc';

	/**
	 * Run stepper as agent, for periodic runs.
	 */
	public static function runAgent(): string
	{
		self::bind(0);

		return __METHOD__ . '();';
	}

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option)
	{
		$deletedCount = self::deleteExpired(self::RECORD_LIMIT);

		return $deletedCount < self::RECORD_LIMIT ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	/**
	 * Deletes the expired buckets of at most $limit rows worth of templates, oldest day first, and reports
	 * the size of the batch it read. The number is not the number of rows gone: the deletion takes every
	 * expired bucket of the templates of the batch, and a template carries one bucket per day it was
	 * started on. Every row it takes is expired all the same, and one more pass of the stepper costs less
	 * than counting them.
	 */
	public static function deleteExpired(int $limit): int
	{
		$oldestKeptDate = self::oldestKeptDate(new DateTime());

		$rows = WorkflowStartStatTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('STAT_DATE', '<', $oldestKeptDate)
			->setOrder(['STAT_DATE' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		if ($rows === [])
		{
			return 0;
		}

		$templateIds = array_values(array_unique(array_map('intval', array_column($rows, 'TEMPLATE_ID'))));

		foreach (array_chunk($templateIds, self::TEMPLATE_IDS_BATCH_SIZE) as $chunk)
		{
			// Keep STAT_DATE in the delete filter because the counter can write current-day buckets for the
			// same templates while cleanup is running.
			WorkflowStartStatTable::deleteByFilter([
				'@TEMPLATE_ID' => $chunk,
				'<STAT_DATE' => $oldestKeptDate,
			]);
		}

		return count($rows);
	}

	/**
	 * The oldest day a bucket is still kept for. The window includes the portal day of $now and begins
	 * one day less than the effective retention window before it. This effective retention is used because
	 * a configured retention shorter than the popularity window would delete days still read by the list.
	 */
	private static function oldestKeptDate(DateTime $now): Date
	{
		$keptDays = StartListOrder::getEffectiveRetentionDays();

		return PortalDate::of($now)->add('-' . ($keptDays - 1) . ' days');
	}
}

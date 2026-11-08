<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartStat;

use Bitrix\Bizproc\Internal\Config\StartListOrder;
use Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Throwable;

/**
 * How many successful manual starts every template of the start list collected over the popularity
 * window. The aggregate is read on its own and never joined to the query of the list: the list joins
 * sections one to many, and a template with two sections would have its buckets counted twice.
 *
 * A rating built out of some of the chunks would be worse than no rating at all - a template whose
 * chunk failed would silently drop to the bottom - so a failed chunk fails the whole read and the
 * caller falls back to zeros for everyone.
 *
 * The read of a chunk is a separate method so that a test double can make it fail; nothing else is
 * meant to be overridden.
 */
class PopularityReader
{
	public const ERROR_CODE = 'BIZPROC_START_LIST_POPULARITY_UNREAD';

	private const TEMPLATE_IDS_BATCH_SIZE = 300;
	private const SUM_ALIAS = 'CNT_SUM';

	/**
	 * The window the popularity is summed over: getPopularityDays() portal days ending on the portal day
	 * of the control moment, both bounds included. The bounds are days of the portal and not of the
	 * viewer, and they are counted in PHP so that no date function of a database reaches the query.
	 *
	 * @return array{from: Date, to: Date}
	 */
	public static function window(DateTime $now): array
	{
		$to = PortalDate::of($now);
		$from = (clone $to)->add('-' . (StartListOrder::getPopularityDays() - 1) . ' days');

		return ['from' => $from, 'to' => $to];
	}

	/**
	 * @param int[] $templateIds Identifiers that already passed the permission check of the list.
	 *
	 * @return Result Data of a successful read is ['popularity' => array<int, int>]; a template with no
	 * bucket in the window is absent from the map and counts as zero.
	 */
	public function read(array $templateIds, Date $from, Date $to): Result
	{
		$result = new Result();

		$templateIds = self::normalize($templateIds);
		if (!$templateIds)
		{
			return $result->setData(['popularity' => []]);
		}

		$popularity = [];
		foreach (array_chunk($templateIds, self::TEMPLATE_IDS_BATCH_SIZE) as $chunk)
		{
			try
			{
				foreach ($this->readChunk($chunk, $from, $to) as $templateId => $count)
				{
					$popularity[$templateId] = $count;
				}
			}
			catch (Throwable $error)
			{
				return $result->addError(
					new Error(
						'Bizproc: the popularity of the start list is unreadable: ' . $error->getMessage(),
						self::ERROR_CODE,
					)
				);
			}
		}

		return $result->setData(['popularity' => $popularity]);
	}

	/**
	 * @param int[] $templateIds
	 *
	 * @return array<int, int>
	 */
	protected function readChunk(array $templateIds, Date $from, Date $to): array
	{
		$rows = WorkflowStartStatTable::query()
			->setSelect(['TEMPLATE_ID'])
			->addSelect(Query::expr(self::SUM_ALIAS)->sum('CNT'))
			->whereIn('TEMPLATE_ID', $templateIds)
			->where('STAT_DATE', '>=', $from)
			->where('STAT_DATE', '<=', $to)
			->fetchAll()
		;

		$popularity = [];
		foreach ($rows as $row)
		{
			$popularity[(int)$row['TEMPLATE_ID']] = (int)$row[self::SUM_ALIAS];
		}

		return $popularity;
	}

	/**
	 * @param int[] $templateIds
	 *
	 * @return int[]
	 */
	private static function normalize(array $templateIds): array
	{
		$normalized = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$normalized[$templateId] = $templateId;
			}
		}

		return array_values($normalized);
	}
}

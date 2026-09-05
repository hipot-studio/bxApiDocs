<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync\Agent;

use Bitrix\Im\V2\Sync\Event;
use Bitrix\Im\V2\Sync\Logger;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * One-shot keyset agent that backfills userFired sync events for already-fired users.
 *
 * Self-reschedules with keyset cursor while there are rows to process;
 * self-deletes (returns '') when the last page is reached.
 */
class FiredUsersAgent
{
	protected const PORTION = 500;

	/**
	 * @param string $from     Lower bound of LAST_ACTIVITY_DATE window (Y-m-d); empty = '2023-01-01'.
	 * @param string $to       Upper bound of LAST_ACTIVITY_DATE window (Y-m-d); empty = current moment.
	 * @param int    $lastId   Keyset cursor: only rows with ID > $lastId are processed.
	 * @return string          Agent string for rescheduling, or '' to self-delete.
	 */
	public static function execute(string $from = '2023-01-01', string $to = '', int $lastId = 0): string
	{
		$fromDate = new DateTime($from, 'Y-m-d');
		if ($to !== '')
		{
			$toDate = new DateTime($to, 'Y-m-d');
		}
		else
		{
			// Round the upper bound UP to next midnight so the deploy day is fully covered
			// (strict '<LAST_ACTIVITY_DATE' keeps today's rows in) and the bound is stable
			// across reschedules: subsequent runs receive this normalized $toDateStr and
			// rebuild EXACTLY the same midnight boundary without any shrink.
			$toDate = new DateTime();
			$toDate->add('1 day');
			$toDate = new DateTime($toDate->format('Y-m-d'), 'Y-m-d');
		}
		$toDateStr = $toDate->format('Y-m-d');

		// Activity window: rows within [from; to) OR with LAST_ACTIVITY_DATE IS NULL
		// (NULL activity is ALWAYS included regardless of the window).
		$activityFilter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->where('LAST_ACTIVITY_DATE', '>=', $fromDate)
					->where('LAST_ACTIVITY_DATE', '<', $toDate)
			)
			->whereNull('LAST_ACTIVITY_DATE')
		;

		// Fetch PORTION fired users with keyset pagination.
		// REAL_USER (EXTERNAL_AUTH_ID NOT IN (external types) OR EXTERNAL_AUTH_ID IS NULL)
		// keeps only real users: NULL/empty EXTERNAL_AUTH_ID intranet users stay in, while
		// service/external accounts are filtered out. The canonical, full set of excluded
		// external types is \Bitrix\Main\UserTable::getExternalUserTypes().
		// 'expr' resolves the REAL_USER ExpressionField reliably (same path as Logger::filterInactive).
		$rows = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', false)
			->where('REAL_USER', 'expr', true)
			->where('ID', '>', $lastId)
			->where($activityFilter)
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::PORTION)
			->fetchAll()
		;

		$logger = Logger::getInstance();

		$events = [];
		foreach ($rows as $row)
		{
			$events[] = new Event(Event::USER_FIRED_EVENT, Event::USER_ENTITY, (int)$row['ID']);
		}

		// Persist the whole portion in a single multiplyMerge upsert.
		$logger->writeGlobalEvents($events);

		$count = count($rows);

		if ($count === self::PORTION)
		{
			$newLastId = (int)$rows[$count - 1]['ID'];

			return self::getAgentName($from, $toDateStr, $newLastId);
		}

		return '';
	}

	protected static function getAgentName(string $from, string $to, int $lastId): string
	{
		$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '2023-01-01';
		$to = ($to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ? $to : '';

		return "\\Bitrix\\Im\\V2\\Sync\\Agent\\FiredUsersAgent::execute('{$from}', '{$to}', {$lastId});";
	}
}

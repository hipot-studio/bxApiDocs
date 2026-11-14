<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\Im\Model\MessageTable;
use Bitrix\ImOpenLines\Model\OperatorTransferTable;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\V2\Statistics\Dto\SessionStatItem;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Batch metrics for a set of sessions for imopenlines.v2.Session.Stat.get.
 */
final class SessionStatService
{
	public const MAX_BATCH = 100;

	public const ERROR_BATCH_LIMIT_EXCEEDED = 'BATCH_LIMIT_EXCEEDED';
	public const ERROR_INVALID_ARGUMENT = 'INVALID_ARGUMENT';

	public function getStat(int $userId, array $sessionIds): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		$sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));

		if (empty($sessionIds))
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_STAT_ID_REQUIRED'), self::ERROR_INVALID_ARGUMENT));

			return $result;
		}

		if (count($sessionIds) > self::MAX_BATCH)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_SESSION_STAT_BATCH_LIMIT_EXCEEDED', ['#MAX#' => self::MAX_BATCH]),
				self::ERROR_BATCH_LIMIT_EXCEEDED
			));

			return $result;
		}

		$allowedOperatorIds = AllowedOperators::getAllowedOperatorIds($userId);

		$sessions = $this->loadSessions($sessionIds, $allowedOperatorIds);
		$transferCounts = $this->loadTransferCounts($sessionIds);
		$messageCounts = $this->loadMessageCounts($sessions);

		// Precompute per-line vote-head visibility for the whole batch in one pass instead
		// of resolving Config::canVoteAsHead() per distinct line inside the masking loop.
		$voteHeadCache = AccessGate::buildVoteHeadVisibilityCache(array_column($sessions, 'CONFIG_ID'), $userId);

		$items = [];
		foreach ($sessionIds as $sessionId)
		{
			$session = $sessions[$sessionId] ?? null;

			if ($session === null)
			{
				$items[] = SessionStatItem::empty($sessionId)->toRestFormat();
				continue;
			}

			$item = SessionStatItem::fromSession(
				$sessionId,
				$session,
				$transferCounts[$sessionId] ?? 0,
				// A loaded session is visible to the caller, so a side with no messages is a
				// real zero, not "unknown". null is reserved for the not-found/not-visible
				// case (see SessionStatItem::empty()); keeping it here would make the client
				// unable to tell an empty side from a hidden metric.
				$messageCounts[$sessionId]['operator'] ?? 0,
				$messageCounts[$sessionId]['client'] ?? 0,
			)->toRestFormat();

			$item = AccessGate::maskVoteHeadFieldsForConfig($item, (int)($session['CONFIG_ID'] ?? 0), $userId, $voteHeadCache);
			$items[] = $item;
		}

		$result->setData(['sessions' => $items]);

		return $result;
	}

	/**
	 * @return array<int, array> Sessions keyed by ID, restricted to allowed operators.
	 */
	private function loadSessions(array $sessionIds, ?array $allowedOperatorIds): array
	{
		$filter = ['=ID' => $sessionIds];

		if (is_array($allowedOperatorIds))
		{
			if (empty($allowedOperatorIds))
			{
				return [];
			}

			$filter['=OPERATOR_ID'] = $allowedOperatorIds;
		}

		$rows = SessionTable::getList([
			'select' => [
				'ID',
				'CONFIG_ID',
				'CHAT_ID',
				'OPERATOR_ID',
				'USER_ID',
				'VOTE',
				'VOTE_HEAD',
				'TIME_FIRST_ANSWER',
				'TIME_CLOSE',
				'MESSAGE_COUNT',
				'START_ID',
				'END_ID',
				'KPI_FIRST_ANSWER_TIME' => 'CONFIG.KPI_FIRST_ANSWER_TIME',
			],
			'filter' => $filter,
		])->fetchAll();

		$sessionsById = [];
		foreach ($rows as $row)
		{
			$kpiTime = (int)($row['KPI_FIRST_ANSWER_TIME'] ?? 0);
			$waitAnswer = $row['TIME_FIRST_ANSWER'] ?? null;

			// TIME_FIRST_ANSWER defaults to 0 for sessions that were never answered, so
			// a plain "<= kpiTime" comparison would wrongly mark them as meeting the KPI.
			$row['KPI_FIRST_ANSWER'] = ($kpiTime > 0 && $waitAnswer !== null && $waitAnswer !== '' && (int)$waitAnswer > 0)
				? ((int)$waitAnswer <= $kpiTime)
				: null;

			$sessionsById[(int)$row['ID']] = $row;
		}

		return $sessionsById;
	}

	/**
	 * @return array<int, int> Transfer counts keyed by SESSION_ID.
	 */
	private function loadTransferCounts(array $sessionIds): array
	{
		$rows = OperatorTransferTable::getList([
			'select' => ['SESSION_ID', 'CNT'],
			'filter' => ['=SESSION_ID' => $sessionIds],
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['SESSION_ID'],
		])->fetchAll();

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['SESSION_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * Best-effort operator/client message split, per session, based on whether the
	 * message author equals the session's USER_ID (client) or not (operator).
	 * System/service messages (AUTHOR_ID = 0 or NOTIFY_EVENT = 'private_system')
	 * are excluded from both counts, matching the convention used elsewhere in the
	 * `im` module for "real" message counting (see Im\Model\MessageTable usage).
	 *
	 * Counts are bounded to each session's own [START_ID, END_ID] message range
	 * (same convention as {@see \Bitrix\ImOpenLines\Session::generateSearchContent()},
	 * see lib/model/session.php:602), not the whole CHAT_ID: a chat can be reused
	 * across several sessions (e.g. a reopened dialog), and counting by CHAT_ID alone
	 * would leak messages from a different session sharing the same chat into this
	 * session's metrics.
	 *
	 * One query handles the whole batch: since ranges are per session (not just per
	 * chat), grouping by CHAT_ID is not enough to tell sessions on the same chat
	 * apart, so a CASE expression maps each matching message row back to its
	 * SESSION_ID before grouping (same batching approach as
	 * {@see SessionListService::loadCrmEntitiesByActivityIds()}). All boundary values
	 * embedded in the CASE expression are ints, so there is no injection risk.
	 *
	 * @return array<int, array{operator:int, client:int}> Keyed by SESSION_ID.
	 */
	private function loadMessageCounts(array $sessionsById): array
	{
		if (!Loader::includeModule('im') || empty($sessionsById))
		{
			return [];
		}

		$ranges = [];
		$chatIds = [];
		foreach ($sessionsById as $sessionId => $session)
		{
			$chatId = (int)($session['CHAT_ID'] ?? 0);
			$startId = (int)($session['START_ID'] ?? 0);
			$endId = (int)($session['END_ID'] ?? 0);

			// END_ID is stamped only when the session closes (see lib/session.php), so an
			// open or reopened session keeps END_ID = 0 while it already has messages from
			// START_ID onwards. Treat that as an open-ended upper bound [START_ID, +inf)
			// instead of skipping the session - otherwise Session.Stat.get would report
			// messagesOperatorCount/messagesClientCount as 0 for every open session even
			// though messagesCount (MESSAGE_COUNT) is > 0. At most one session per chat is
			// open at a time and it is always the latest on the chat, so an open upper bound
			// cannot pull in messages belonging to a different (earlier, closed) session that
			// reused the same chat.
			$isOpenRange = $endId <= 0;

			if ($chatId <= 0 || $startId <= 0 || (!$isOpenRange && $endId < $startId))
			{
				continue;
			}

			$ranges[(int)$sessionId] = [
				'chatId' => $chatId,
				'userId' => (int)($session['USER_ID'] ?? 0),
				'startId' => $startId,
				'endId' => $isOpenRange ? null : $endId,
			];
			$chatIds[$chatId] = true;
		}

		if (empty($ranges))
		{
			return [];
		}

		$caseParts = [];
		$rangeConditions = [];
		foreach ($ranges as $sessionId => $range)
		{
			if ($range['endId'] === null)
			{
				// Open session: no upper bound (see the [START_ID, +inf) note above).
				$caseParts[] = sprintf(
					'WHEN CHAT_ID = %d AND ID >= %d THEN %d',
					$range['chatId'],
					$range['startId'],
					$sessionId
				);

				$rangeConditions[] = [
					'LOGIC' => 'AND',
					'=CHAT_ID' => $range['chatId'],
					'>=ID' => $range['startId'],
				];

				continue;
			}

			$caseParts[] = sprintf(
				'WHEN CHAT_ID = %d AND ID BETWEEN %d AND %d THEN %d',
				$range['chatId'],
				$range['startId'],
				$range['endId'],
				$sessionId
			);

			$rangeConditions[] = [
				'LOGIC' => 'AND',
				'=CHAT_ID' => $range['chatId'],
				'>=ID' => $range['startId'],
				'<=ID' => $range['endId'],
			];
		}

		$sessionIdExpression = 'CASE ' . implode(' ', $caseParts) . ' END';

		$rows = MessageTable::getList([
			'select' => ['SESSION_ID', 'AUTHOR_ID', 'CNT'],
			'filter' => [
				'=CHAT_ID' => array_keys($chatIds),
				'!=AUTHOR_ID' => 0,
				// NOTIFY_EVENT is nullable: a plain '!=NOTIFY_EVENT' => 'private_system'
				// predicate excludes NULL rows too (NULL != 'x' is unknown, not true in SQL),
				// silently dropping every "real" message that never got a notify event.
				[
					'LOGIC' => 'OR',
					'=NOTIFY_EVENT' => null,
					'!=NOTIFY_EVENT' => 'private_system',
				],
				array_merge(['LOGIC' => 'OR'], $rangeConditions),
			],
			'runtime' => [
				new ExpressionField('SESSION_ID', $sessionIdExpression),
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['SESSION_ID', 'AUTHOR_ID'],
		])->fetchAll();

		$counts = [];
		foreach ($rows as $row)
		{
			$sessionId = (int)($row['SESSION_ID'] ?? 0);
			if ($sessionId === 0 || !isset($ranges[$sessionId]))
			{
				// Should not happen (every counted row matched one of the per-session
				// range conditions in the filter), but guards against a stray NULL
				// from the CASE expression instead of miscounting it into session 0.
				continue;
			}

			$authorId = (int)$row['AUTHOR_ID'];
			$cnt = (int)$row['CNT'];

			$counts[$sessionId] ??= ['operator' => 0, 'client' => 0];

			if ($authorId === $ranges[$sessionId]['userId'])
			{
				$counts[$sessionId]['client'] += $cnt;
			}
			else
			{
				$counts[$sessionId]['operator'] += $cnt;
			}
		}

		return $counts;
	}
}

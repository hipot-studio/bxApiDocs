<?php

namespace Bitrix\ImOpenLines\V2\Statistics\Dto;

use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * Read-only REST representation of aggregated line statistics for a period, for
 * imopenlines.v2.Stat.get.
 *
 * All numeric metrics default to 0 (never null) when there is no underlying data —
 * see the constructor defaults and StatService's aggregation queries, which always
 * coalesce COUNT/AVG results.
 *
 * `sessionsByHour` always has exactly 24 entries (hours 0..23, in the server's local
 * time zone — the same time zone MySQL's HOUR() applies to the stored DATE_CREATE
 * column, i.e. the portal's server TZ, not per-user TZ), with 0 for hours that have
 * no sessions.
 */
final class StatResult implements RestConvertible
{
	public function __construct(
		private readonly int $totalSessions,
		private readonly int $closedSessions,
		private readonly int $spamSessions,
		private readonly float $avgWaitAnswer,
		private readonly float $avgSessionDuration,
		private readonly int $likeCount,
		private readonly int $dislikeCount,
		private readonly int $votedSessions,
		private readonly float $positiveRate,
		private readonly int $kpiFirstAnswerOk,
		private readonly int $kpiFirstAnswerFail,
		private readonly array $sessionsBySource,
		private readonly array $sessionsByHour,
		private readonly array $sessionsByOperator,
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'stat';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'totalSessions' => $this->totalSessions,
			'closedSessions' => $this->closedSessions,
			'spamSessions' => $this->spamSessions,
			'avgWaitAnswer' => $this->avgWaitAnswer,
			'avgSessionDuration' => $this->avgSessionDuration,
			'likeCount' => $this->likeCount,
			'dislikeCount' => $this->dislikeCount,
			'votedSessions' => $this->votedSessions,
			'positiveRate' => $this->positiveRate,
			'kpiFirstAnswerOk' => $this->kpiFirstAnswerOk,
			'kpiFirstAnswerFail' => $this->kpiFirstAnswerFail,
			'sessionsBySource' => $this->sessionsBySource,
			'sessionsByHour' => $this->sessionsByHour,
			'sessionsByOperator' => $this->sessionsByOperator,
		];
	}
}

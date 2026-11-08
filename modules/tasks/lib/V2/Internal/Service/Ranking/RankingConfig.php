<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Ranking;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Tasks\Feature\RankingFeature;

/**
 * Per-portal settings of the ranking feature: kill-switch, throttle windows,
 * engagement threshold, relevance window and query limits. Consumers read these,
 * never redefine them. The algorithm itself is not configurable: event weights
 * live in {@see ScoreCalculator}, candidate pool sizes in {@see RankingService}.
 */
final class RankingConfig
{
	private const MODULE_ID = 'tasks';

	private const OPTION_THROTTLE_WINDOW = 'tasks_ranking_throttle_window';
	private const DEFAULT_THROTTLE_WINDOW = 3600;
	private const OPTION_THROTTLE_WINDOW_RELATION = 'tasks_ranking_throttle_window_relation';
	private const DEFAULT_THROTTLE_WINDOW_RELATION = 5;
	private const MIN_THROTTLE_WINDOW = 5;

	private const OPTION_OPEN_USER_THROTTLE_WINDOW = 'tasks_ranking_open_user_throttle_window';

	private const OPTION_ENGAGEMENT_THRESHOLD = 'tasks_ranking_engagement_threshold';
	private const DEFAULT_ENGAGEMENT_THRESHOLD = 15;
	private const MIN_ENGAGEMENT_THRESHOLD = 1;
	private const MAX_ENGAGEMENT_THRESHOLD = 3600;

	private const OPTION_RELEVANCE_WINDOW_DAYS = 'tasks_ranking_relevance_window_days';
	private const DEFAULT_RELEVANCE_WINDOW_DAYS = 30;

	// Max journal rows scanned per score calculation. Doubles as the per-user storage
	// cap: rows beyond the newest N are unreadable by scoring, so the trim drops them.
	private const OPTION_EVENTS_LIMIT = 'tasks_ranking_events_limit';
	private const DEFAULT_EVENTS_LIMIT = 10000;

	private const OPTION_TRIM_TRIGGER_WINDOW = 'tasks_ranking_trim_trigger_window';
	private const DEFAULT_TRIM_TRIGGER_WINDOW = 86400;
	private const MIN_TRIM_TRIGGER_WINDOW = 60;

	private const OPTION_CANDIDATE_LIMIT = 'tasks_ranking_candidate_limit';
	private const DEFAULT_CANDIDATE_LIMIT = 500;

	private const OPTION_PURGE_BATCH_SIZE = 'tasks_ranking_purge_batch_size';
	private const DEFAULT_PURGE_BATCH_SIZE = 500;

	public static function isEnabled(): bool
	{
		return Feature::isEnabled(RankingFeature::class);
	}

	public static function getThrottleWindow(EventType $eventType): int
	{
		$window = match ($eventType)
		{
			EventType::Relation => (int)Option::get(
				self::MODULE_ID,
				self::OPTION_THROTTLE_WINDOW_RELATION,
				(string)self::DEFAULT_THROTTLE_WINDOW_RELATION,
			),
			default => (int)Option::get(
				self::MODULE_ID,
				self::OPTION_THROTTLE_WINDOW,
				(string)self::DEFAULT_THROTTLE_WINDOW,
			),
		};

		return max($window, self::getMinThrottleWindow());
	}

	public static function getMinThrottleWindow(): int
	{
		return self::MIN_THROTTLE_WINDOW;
	}

	public static function getOpenUserThrottleWindow(): int
	{
		$window = (int)Option::get(
			self::MODULE_ID,
			self::OPTION_OPEN_USER_THROTTLE_WINDOW,
			(string)self::MIN_THROTTLE_WINDOW,
		);

		return max($window, self::MIN_THROTTLE_WINDOW);
	}

	/**
	 * Clamped on both ends: below a second every card open becomes a signal, above
	 * an hour the client timer outlives the card session.
	 */
	public static function getEngagementThresholdSeconds(): int
	{
		$threshold = (int)Option::get(
			self::MODULE_ID,
			self::OPTION_ENGAGEMENT_THRESHOLD,
			(string)self::DEFAULT_ENGAGEMENT_THRESHOLD,
		);

		return min(
			max($threshold, self::MIN_ENGAGEMENT_THRESHOLD),
			self::MAX_ENGAGEMENT_THRESHOLD,
		);
	}

	// Clamped to >= 1: a zero or negative window would leave scoring without events
	// and let the age purge drop the whole journal.
	public static function getRelevanceWindowDays(): int
	{
		return max(
			1,
			(int)Option::get(
				self::MODULE_ID,
				self::OPTION_RELEVANCE_WINDOW_DAYS,
				(string)self::DEFAULT_RELEVANCE_WINDOW_DAYS,
			),
		);
	}

	// Clamped to >= 1: a non-positive value would drop the ORM LIMIT altogether and
	// let the whole journal load.
	public static function getEventsLimit(): int
	{
		return max(
			1,
			(int)Option::get(
				self::MODULE_ID,
				self::OPTION_EVENTS_LIMIT,
				(string)self::DEFAULT_EVENTS_LIMIT,
			),
		);
	}

	public static function getTrimTriggerWindow(): int
	{
		$window = (int)Option::get(
			self::MODULE_ID,
			self::OPTION_TRIM_TRIGGER_WINDOW,
			(string)self::DEFAULT_TRIM_TRIGGER_WINDOW,
		);

		return max($window, self::MIN_TRIM_TRIGGER_WINDOW);
	}

	public static function getCandidateLimit(): int
	{
		return max(
			1,
			(int)Option::get(
				self::MODULE_ID,
				self::OPTION_CANDIDATE_LIMIT,
				(string)self::DEFAULT_CANDIDATE_LIMIT,
			),
		);
	}

	public static function getPurgeBatchSize(): int
	{
		return max(
			1,
			(int)Option::get(
				self::MODULE_ID,
				self::OPTION_PURGE_BATCH_SIZE,
				(string)self::DEFAULT_PURGE_BATCH_SIZE,
			),
		);
	}
}

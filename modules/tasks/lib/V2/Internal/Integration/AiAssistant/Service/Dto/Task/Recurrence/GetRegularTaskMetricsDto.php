<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodBucketer;

class GetRegularTaskMetricsDto extends TemplateIdsDto
{
	public const MAX_ITEMS = 50;
	public const DAY_SECONDS = 86_400;
	/**
	 * The widest window the section UI can ask for is a calendar year, so a leap year is the upper
	 * bound here as well: the aggregate projects the schedule day by day for every template, and that
	 * projection - not the number of buckets - is what a long window makes expensive.
	 */
	public const MAX_INTERVAL_DAYS = 366;
	public const MAX_INTERVAL_SECONDS = self::MAX_INTERVAL_DAYS * self::DAY_SECONDS;
	/**
	 * The aggregate builds one `CASE` branch per bucket and repeats the expression in `GROUP BY`, so
	 * the bucket count is what the size of the SQL statement is made of.
	 */
	public const MAX_BUCKETS = 60;
	/**
	 * Every fetched instance row is evaluated against every bucket branch, so the work behind the
	 * answer grows with buckets multiplied by templates even though the answer itself does not. The
	 * value is the measured corner of the two limits above (60 buckets for 50 templates), which costs
	 * less than the widest window, so nothing that fits into both of them is rejected. It stays a
	 * fixed ceiling on purpose: raising the bucket or the template limit past it must be a decision,
	 * not a side effect.
	 */
	public const MAX_BUCKET_TEMPLATE_PAIRS = 3000;
	public const MIN_TZ_OFFSET = -43_200;
	public const MAX_TZ_OFFSET = 50_400;
	private const MIN_SUPPORTED_TIMESTAMP = -62_135_596_800;
	private const MAX_SUPPORTED_TIMESTAMP = 253_402_300_799;
	public const ALLOWED_SCALES = ['day', 'week', 'month', 'year'];

	protected function __construct(
		?array $templateIds = null,
		?array $taskIds = null,
		#[InArray(self::ALLOWED_SCALES, strict: true)]
		public readonly ?string $scale = null,
		#[NotEmpty(allowZero: true)]
		public readonly ?int $from = null,
		#[NotEmpty(allowZero: true)]
		public readonly ?int $to = null,
		#[NotEmpty(allowZero: true)]
		#[Range(min: self::MIN_TZ_OFFSET, max: self::MAX_TZ_OFFSET)]
		public readonly ?int $tzOffset = null,
	)
	{
		parent::__construct($templateIds, $taskIds);
	}

	public static function fromArray(array $props): static
	{
		$templateIds = static::mapArray($props, 'templateIds');
		$taskIds = static::mapArray($props, 'taskIds');
		$scale = static::mapString($props, 'scale');
		$from = static::mapInteger($props, 'from');
		$to = static::mapInteger($props, 'to');
		$tzOffset = static::mapInteger($props, 'tzOffset');

		static::validateIdentifierIds($templateIds, $taskIds);
		static::validateInterval($from, $to, $tzOffset);
		static::validateWorkload(
			$templateIds ?? $taskIds,
			$templateIds !== null ? 'templateIds' : 'taskIds',
			$scale,
			$from,
			$to,
			$tzOffset,
		);

		return new static(
			templateIds: $templateIds,
			taskIds: $taskIds,
			scale: $scale,
			from: $from,
			to: $to,
			tzOffset: $tzOffset,
		);
	}

	private static function validateInterval(?int $from, ?int $to, ?int $tzOffset): void
	{
		if ($from === null || $to === null || $tzOffset === null || $to < $from)
		{
			return;
		}

		$minimumTimestamp = self::MIN_SUPPORTED_TIMESTAMP - $tzOffset;
		$maximumTimestamp = self::MAX_SUPPORTED_TIMESTAMP - $tzOffset;
		if (
			$from < $minimumTimestamp
			|| $from > $maximumTimestamp
			|| $to < $minimumTimestamp
			|| $to > $maximumTimestamp
		)
		{
			throw new DtoValidationException(
				'Metrics timestamps with the timezone offset must stay within years 0001 to 9999.',
			);
		}

		if ($to - $from > self::MAX_INTERVAL_SECONDS)
		{
			throw new DtoValidationException(
				'The metrics interval must not exceed ' . self::MAX_INTERVAL_DAYS . ' days.',
			);
		}
	}

	/**
	 * The answer is one row per bucket, but the work behind it is not: the aggregate builds a `CASE`
	 * branch per bucket and projects the schedule of every template day by day. An inverted interval
	 * returns an empty result without any of that work, so it is left alone here.
	 *
	 * @param int[]|null $identifierIds
	 */
	private static function validateWorkload(
		?array $identifierIds,
		string $identifierFieldName,
		?string $scale,
		?int $from,
		?int $to,
		?int $tzOffset,
	): void
	{
		if (
			empty($identifierIds)
			|| $scale === null
			|| !PeriodBucketer::isValidScale($scale)
			|| $from === null
			|| $to === null
			|| $tzOffset === null
			|| $to < $from
		)
		{
			return;
		}

		$bucketCount = count((new PeriodBucketer())->buildBucketKeys($scale, $from, $to, $tzOffset));
		if ($bucketCount > self::MAX_BUCKETS)
		{
			throw new DtoValidationException(
				'Scale "' . $scale . '" splits this interval into ' . $bucketCount . ' buckets, '
				. 'but a metrics request must not exceed ' . self::MAX_BUCKETS . ' buckets. '
				. 'Use a coarser scale or a shorter interval.',
			);
		}

		$templateCount = count($identifierIds);
		$pairs = $bucketCount * $templateCount;
		if ($pairs > self::MAX_BUCKET_TEMPLATE_PAIRS)
		{
			throw new DtoValidationException(
				$bucketCount . ' buckets for ' . $templateCount . ' ' . $identifierFieldName . ' give ' . $pairs
				. ' bucket-template pairs, but a metrics request must not exceed '
				. self::MAX_BUCKET_TEMPLATE_PAIRS . '. '
				. 'Use a coarser scale, a shorter interval, or fewer ' . $identifierFieldName . '.',
			);
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;

/**
 * Formats and writes log entries for rejected public booking (getFillFormData) requests.
 *
 * Untrusted request fields are sanitized (control characters stripped, length capped) before
 * they reach the log, so a crafted request cannot forge multi-line log entries. The raw sec is
 * never logged, only a short sha256 tail. A best-effort per-hour quota caps how many rejected
 * requests are recorded, so a request flood cannot inflate the log unbounded.
 *
 * The log sink, the per-bucket counter and the clock are injectable seams (with real defaults)
 * so sanitization and quota logic are unit-testable without touching the cache or the log.
 */
final class RejectedRequestLogger
{
	private const MAX_FIELD_LENGTH = 64;
	private const MAX_RECORDS_PER_HOUR = 60;
	private const SEC_HASH_LENGTH = 5;
	private const BUCKET_SECONDS = 3600;
	private const LOG_MODULE = 'calendar';
	private const CACHE_DIR = '/calendar/public_booking_rejected_log';

	/** @var callable(string): void */
	private $sink;

	/** @var callable(string): int bucket key -> post-increment count */
	private $counter;

	/** @var callable(): int current unix timestamp */
	private $clock;

	public function __construct(?callable $sink = null, ?callable $counter = null, ?callable $clock = null)
	{
		$this->sink = $sink ?? static fn(string $message): mixed => AddMessage2Log($message, self::LOG_MODULE, 0);
		$this->counter = $counter ?? fn(string $bucketKey): int => $this->incrementBucketCounter($bucketKey);
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * @param array $fields raw (untrusted) request values:
	 *   sourceType: string, formId: int, fieldCode: string, sec: string,
	 *   from: string, to: string, hasSettingsData: bool
	 */
	public function log(string $reason, array $fields): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		if (!$this->withinQuota())
		{
			return;
		}

		($this->sink)($this->formatMessage($reason, $fields));
	}

	public static function sanitizeField(string $value): string
	{
		// Strip C0 controls (\r, \n, \t, ...) and DEL. Byte range only; UTF-8 continuation
		// bytes are >= 0x80, so multibyte characters stay intact.
		$clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';

		return mb_substr($clean, 0, self::MAX_FIELD_LENGTH);
	}

	private function isEnabled(): bool
	{
		return Option::get('calendar', 'log_public_booking_legacy', 'Y') === 'Y';
	}

	private function withinQuota(): bool
	{
		$bucketKey = 'h' . intdiv(($this->clock)(), self::BUCKET_SECONDS);

		return ($this->counter)($bucketKey) <= self::MAX_RECORDS_PER_HOUR;
	}

	private function formatMessage(string $reason, array $fields): string
	{
		return sprintf(
			'[public-booking] %s | sourceType=%s formId=%d fieldCode=%s sec-hash=%s from=%s to=%s hasSettingsData=%s',
			self::sanitizeField($reason),
			self::sanitizeField((string)($fields['sourceType'] ?? '')),
			(int)($fields['formId'] ?? 0),
			self::sanitizeField((string)($fields['fieldCode'] ?? '')),
			$this->secHash((string)($fields['sec'] ?? '')),
			self::sanitizeField((string)($fields['from'] ?? '')),
			self::sanitizeField((string)($fields['to'] ?? '')),
			!empty($fields['hasSettingsData']) ? 'Y' : 'N',
		);
	}

	private function secHash(string $sec): string
	{
		return $sec === '' ? '' : substr(hash('sha256', $sec), -self::SEC_HASH_LENGTH);
	}

	/**
	 * Best-effort per-bucket counter backed by the standard Bitrix cache.
	 * forceRewriting is required: startDataCache would otherwise refuse to overwrite a still-valid
	 * entry, freezing the counter at its first value.
	 *
	 * Once the bucket is saturated the counter stops rewriting the shared entry and just reports
	 * "over the cap", so a flood past the quota no longer keeps generating cache I/O for the rest
	 * of the hour. The counter is intentionally best-effort: a concurrent read-modify-write may let
	 * a few extra records slip through, which is acceptable for a log cap (not a security control).
	 */
	private function incrementBucketCounter(string $bucketKey): int
	{
		$cache = Cache::createInstance();

		$count = 0;
		if ($cache->initCache(self::BUCKET_SECONDS, $bucketKey, self::CACHE_DIR))
		{
			$count = (int)($cache->getVars()['count'] ?? 0);
		}

		if ($count >= self::MAX_RECORDS_PER_HOUR)
		{
			return self::MAX_RECORDS_PER_HOUR + 1;
		}

		$count++;

		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::BUCKET_SECONDS, $bucketKey, self::CACHE_DIR))
		{
			$cache->endDataCache(['count' => $count]);
		}
		$cache->forceRewriting(false);

		return $count;
	}
}

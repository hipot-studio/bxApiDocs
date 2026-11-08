<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Main\Config\Option;

/**
 * Time limits of the webhook save path, tunable on the portal by module options.
 *
 * A value outside its range - or not a number at all - falls back to the default, so a mistyped option
 * cannot make the webhook hang or give up before the first byte. The pauses between the attempts are not
 * an option: they only matter next to the budget, which is.
 */
final class SaveTimingConfig
{
	public const OPTION_CONNECT_TIMEOUT = 'disk_onlyoffice_save_connect_timeout';
	public const OPTION_STREAM_TIMEOUT = 'disk_onlyoffice_save_stream_timeout';
	public const OPTION_DOWNLOAD_ATTEMPTS = 'disk_onlyoffice_save_download_attempts';
	public const OPTION_DOWNLOAD_BUDGET = 'disk_onlyoffice_save_download_budget';
	public const OPTION_DOWNLOAD_LIMIT = 'disk_onlyoffice_save_download_limit';

	public const DEFAULT_CONNECT_TIMEOUT = 10;
	public const DEFAULT_STREAM_TIMEOUT = 30;
	public const DEFAULT_DOWNLOAD_ATTEMPTS = 3;
	public const DEFAULT_DOWNLOAD_BUDGET = 40;
	public const DEFAULT_DOWNLOAD_LIMIT = 120;

	private const MODULE_ID = 'disk';

	private const CONNECT_TIMEOUT_RANGE = [1, 120];
	private const STREAM_TIMEOUT_RANGE = [1, 300];
	private const DOWNLOAD_ATTEMPTS_RANGE = [1, 5];
	private const DOWNLOAD_BUDGET_RANGE = [1, 600];
	private const DOWNLOAD_LIMIT_RANGE = [1, 900];

	private const PAUSES_BETWEEN_ATTEMPTS = [1, 2];

	/** Seconds of processor time left to the writing of the version once the download spent its limit. */
	private const WRITE_RESERVE = 70;

	public readonly int $connectTimeout;
	public readonly int $streamTimeout;
	public readonly int $attempts;

	/**
	 * Seconds after which no further attempt is started, counted from the start of the processing of the
	 * webhook. It bounds the retrying alone: the first attempt is never refused by it - nothing has been
	 * spent by then - and the time one attempt may last is the download limit below.
	 */
	public readonly int $downloadBudget;

	/**
	 * Seconds the downloading as a whole may spend, counted from the start of the processing of the
	 * webhook. An attempt may still be started with the budget almost gone, and it may spend the
	 * connecting and the waiting for the first byte before a single byte of the document arrives, so a
	 * limit shorter than those two together would end a transfer which has not begun: such a value is
	 * raised to them.
	 */
	public readonly int $downloadLimit;

	/**
	 * Seconds for the `set_time_limit()` of the webhook. On Unix that is processor time: the waiting for
	 * the editor server, for the disk and for the database costs it nothing, and it starts over at the
	 * call. Nothing which has to expire by the clock may be derived from it.
	 */
	public readonly int $executionLimit;

	public function __construct(
		int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
		int $streamTimeout = self::DEFAULT_STREAM_TIMEOUT,
		int $attempts = self::DEFAULT_DOWNLOAD_ATTEMPTS,
		int $downloadBudget = self::DEFAULT_DOWNLOAD_BUDGET,
		int $downloadLimit = self::DEFAULT_DOWNLOAD_LIMIT,
	)
	{
		$this->connectTimeout = self::normalize(
			$connectTimeout,
			self::CONNECT_TIMEOUT_RANGE,
			self::DEFAULT_CONNECT_TIMEOUT,
		);
		$this->streamTimeout = self::normalize(
			$streamTimeout,
			self::STREAM_TIMEOUT_RANGE,
			self::DEFAULT_STREAM_TIMEOUT,
		);
		$this->attempts = self::normalize(
			$attempts,
			self::DOWNLOAD_ATTEMPTS_RANGE,
			self::DEFAULT_DOWNLOAD_ATTEMPTS,
		);
		$this->downloadBudget = self::normalize(
			$downloadBudget,
			self::DOWNLOAD_BUDGET_RANGE,
			self::DEFAULT_DOWNLOAD_BUDGET,
		);

		$this->downloadLimit = max(
			self::normalize($downloadLimit, self::DOWNLOAD_LIMIT_RANGE, self::DEFAULT_DOWNLOAD_LIMIT),
			$this->connectTimeout + $this->streamTimeout,
		);
		$this->executionLimit = $this->downloadLimit + self::WRITE_RESERVE;
	}

	public static function fromModuleOptions(): self
	{
		return new self(
			connectTimeout: self::readOption(self::OPTION_CONNECT_TIMEOUT, self::DEFAULT_CONNECT_TIMEOUT),
			streamTimeout: self::readOption(self::OPTION_STREAM_TIMEOUT, self::DEFAULT_STREAM_TIMEOUT),
			attempts: self::readOption(self::OPTION_DOWNLOAD_ATTEMPTS, self::DEFAULT_DOWNLOAD_ATTEMPTS),
			downloadBudget: self::readOption(self::OPTION_DOWNLOAD_BUDGET, self::DEFAULT_DOWNLOAD_BUDGET),
			downloadLimit: self::readOption(self::OPTION_DOWNLOAD_LIMIT, self::DEFAULT_DOWNLOAD_LIMIT),
		);
	}

	/**
	 * Seconds to wait before the given attempt; the first attempt waits for nothing.
	 */
	public function getPauseBeforeAttempt(int $attempt): int
	{
		if ($attempt < 2)
		{
			return 0;
		}

		$pauses = self::PAUSES_BETWEEN_ATTEMPTS;

		return $pauses[$attempt - 2] ?? $pauses[array_key_last($pauses)];
	}

	private static function readOption(string $name, int $default): int
	{
		$value = Option::get(self::MODULE_ID, $name, (string)$default);

		return is_numeric($value) ? (int)$value : $default;
	}

	/**
	 * @param array{int, int} $range
	 */
	private static function normalize(int $value, array $range, int $default): int
	{
		[$min, $max] = $range;

		return $value >= $min && $value <= $max ? $value : $default;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Driver;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;

final class Configuration
{
	public const DEFAULT_MAX_FILESIZE = 104857600;

	/**
	 * Ceilings of the ALG-RETRY settings. The open cycle waits inside the synchronous request the
	 * editor page is rendered by, so a value written by mistake - 5000 meant as milliseconds, seconds
	 * on the wire - must cost a bounded wait instead of a worker held for the whole of it. A value
	 * above the ceiling is brought down to it, not thrown away.
	 *
	 * The ceilings stand at twice the canonical defaults of ADR (a budget of 15 seconds, three
	 * attempts, a pause of up to 5): enough room for an experiment on a stand, still far below the
	 * execution time of a request and the rate limit of the platform. The reserve for the worst answer
	 * and the threshold of a long attempt are measured against the budget, so they share its ceiling -
	 * above it neither of them means anything the cycle could act on.
	 */
	private const MAX_OPEN_BUDGET_SEC = 30;
	private const MAX_OPEN_ATTEMPTS = 5;
	private const MAX_OPEN_WORST_ANSWER_SEC = self::MAX_OPEN_BUDGET_SEC;
	private const MAX_OPEN_LONG_ATTEMPT_SEC = self::MAX_OPEN_BUDGET_SEC;
	private const MAX_OPEN_PAUSE_SEC = 10;

	/** @var array|null override values from bitrix/php_interface/disk-vibeoffice.php */
	private ?array $localValues = null;

	public function __construct()
	{
		$this->loadLocalValues();
	}

	/**
	 * Turns the portal flag off so that new document opens no longer route to vibeoffice.
	 *
	 * This is the first, mandatory step of the rollback drain ({@see \Bitrix\Disk\Document\Vibeoffice\DrainService},
	 * ALG-DRAIN): once cleared, {@see VibeofficeHandler::isEnabled()} returns false and the
	 * dispatch intercept stops forwarding to this handler, while drain still finishes the
	 * already-open sessions.
	 */
	public function disableOnPortal(): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled', 'N');
	}

	public function isStableDocumentKeyEnabled(): bool
	{
		return in_array(
			$this->getValue('stable_document_key_enabled', 'disk_vibeoffice_stable_document_key_enabled'),
			['Y', '1'],
			true,
		);
	}

	/**
	 * Base URL of the vibeoffice editing platform.
	 */
	public function getServer(): ?string
	{
		return $this->getValue('server', 'disk_vibeoffice_server');
	}

	/**
	 * Key identifier (X-VO-Key header value, e.g. vok_...).
	 */
	public function getKeyId(): ?string
	{
		return $this->getValue('key_id', 'disk_vibeoffice_key_id');
	}

	/**
	 * Secret used to sign outgoing protocol-client requests.
	 * Backend-only: never returned to the browser / document service.
	 */
	public function getApiSecret(): ?string
	{
		return $this->getValue('api_secret', 'disk_vibeoffice_api_secret');
	}

	/**
	 * Secret used to verify incoming webhooks.
	 */
	public function getWebhookSecret(): ?string
	{
		return $this->getValue('webhook_secret', 'disk_vibeoffice_webhook_secret');
	}

	/**
	 * Workgroup (project) ids the vibeoffice engine is narrowed to.
	 *
	 * The php_interface override (`enabled_group_ids`, an int[]) wins over the module option
	 * `disk_vibeoffice_enabled_groups` (a CSV string). Empty / non-numeric / non-positive entries
	 * are dropped and the result is a de-duplicated int[]. An empty array means no narrowing — the
	 * feature stays portal-wide (current behaviour).
	 *
	 * @return int[]
	 */
	public function getEnabledGroupIds(): array
	{
		$local = $this->getLocalValues('enabled_group_ids');
		if (is_array($local))
		{
			return $this->normalizeGroupIds($local);
		}

		$csv = (string)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled_groups', '');

		return $this->normalizeGroupIds(explode(',', $csv));
	}

	/**
	 * @param array $values
	 * @return int[]
	 */
	private function normalizeGroupIds(array $values): array
	{
		$ids = [];
		foreach ($values as $value)
		{
			if (is_string($value))
			{
				$value = trim($value);
			}
			if (!is_numeric($value))
			{
				continue;
			}
			$id = (int)$value;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/**
	 * Wall-clock budget of the whole open cycle, in seconds (ALG-RETRY `budgetSec`).
	 *
	 * Read once per open cycle by {@see OpenRetryPolicy::createFromConfiguration()}, never per attempt.
	 */
	public function getOpenBudgetSeconds(): int
	{
		return $this->getIntValue(
			'open_budget_sec',
			'disk_vibeoffice_open_budget_sec',
			OpenRetryPolicy::DEFAULT_BUDGET_SEC,
			self::MAX_OPEN_BUDGET_SEC,
		);
	}

	/**
	 * Cap on the number of `open` attempts of one cycle, the first one included (ALG-RETRY `maxAttempts`).
	 */
	public function getOpenMaxAttempts(): int
	{
		return $this->getIntValue(
			'open_max_attempts',
			'disk_vibeoffice_open_max_attempts',
			OpenRetryPolicy::DEFAULT_MAX_ATTEMPTS,
			self::MAX_OPEN_ATTEMPTS,
		);
	}

	/**
	 * Reserve for the worst platform answer, in seconds (ALG-RETRY `worstAnswerSec`): a new attempt
	 * starts only while the budget still holds a pause plus this reserve.
	 */
	public function getOpenWorstAnswerSeconds(): int
	{
		return $this->getIntValue(
			'open_worst_answer_sec',
			'disk_vibeoffice_open_worst_answer_sec',
			OpenRetryPolicy::DEFAULT_WORST_ANSWER_SEC,
			self::MAX_OPEN_WORST_ANSWER_SEC,
		);
	}

	/**
	 * Threshold telling a long failed attempt from an instant one, in seconds (ALG-RETRY `longAttemptSec`).
	 */
	public function getOpenLongAttemptSeconds(): int
	{
		return $this->getIntValue(
			'open_long_attempt_sec',
			'disk_vibeoffice_open_long_attempt_sec',
			OpenRetryPolicy::DEFAULT_LONG_ATTEMPT_SEC,
			self::MAX_OPEN_LONG_ATTEMPT_SEC,
		);
	}

	/**
	 * Cap on the pause the platform asks for in `Retry-After`, in seconds (ALG-RETRY `maxPauseSec`).
	 */
	public function getOpenMaxPauseSeconds(): int
	{
		return $this->getIntValue(
			'open_max_pause_sec',
			'disk_vibeoffice_open_max_pause_sec',
			OpenRetryPolicy::DEFAULT_MAX_PAUSE_SEC,
			self::MAX_OPEN_PAUSE_SEC,
		);
	}

	public function getMaxFileSize(): int
	{
		$value = $this->getValue('max_filesize', 'disk_vibeoffice_max_filesize');

		if ($value === null || $value === '')
		{
			return self::DEFAULT_MAX_FILESIZE;
		}

		return (int)$value;
	}

	/**
	 * Resolves a value, giving the php_interface override priority over the module option.
	 */
	private function getValue(string $key, string $optionName): ?string
	{
		$local = $this->getLocalValues($key);
		if ($local !== null)
		{
			return (string)$local;
		}

		return Option::get(Driver::INTERNAL_MODULE_ID, $optionName, null);
	}

	/**
	 * Resolves a positive integer value within the range the cycle can work by: a missing, empty or
	 * non-numeric one yields the default, one above the ceiling is brought down to the ceiling.
	 */
	private function getIntValue(string $key, string $optionName, int $default, int $max): int
	{
		$value = $this->getValue($key, $optionName);

		if ($value === null || !is_numeric($value))
		{
			return $default;
		}

		$value = (int)$value;

		return $value > 0 ? min($value, $max) : $default;
	}

	private function getLocalValues(string $key): mixed
	{
		return $this->localValues[$key] ?? null;
	}

	private function loadLocalValues(): void
	{
		$path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/disk-vibeoffice.php';
		if (File::isFileExists($path))
		{
			$localValues = require($path);
			if (is_array($localValues))
			{
				$this->localValues = $localValues;
			}
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class EditorFailureReport
{
	private const FIELDS = [
		'document_session_id', 'report_attempt_id', 'attempt_id_source', 'load_attempt_id',
		'diagnostic_completeness', 'failure_source', 'outcome', 'retry_count', 'code', 'stage',
		'attempt', 'engineCode', 'httpStatus', 'elapsed_ms', 'finalReason', 'refreshOutcome',
	];

	private const CODES = [
		'api-js-load-failed', 'editor-create-failed', 'invalid-open-config', 'refresh-fetch-failed',
		'session-dead', 'recovery-failed', 'ds-error', 'save-refused', 'no-capacity',
		'reopened-unsaved-lost',
	];

	private const RECOVERY_CODES_WITHOUT_ENGINE = [
		'refresh-fetch-failed', 'save-refused', 'no-capacity', 'recovery-failed',
		'editor-create-failed', 'api-js-load-failed', 'invalid-open-config', 'ds-error',
	];

	private const CREATE_STAGES = [
		'api-create', 'ready', 'document-ready', 'refresh', 'validate-config', 'apply-config',
	];

	private const FINAL_REASONS = [
		...self::CODES, 'app-ready-missing', 'timeout', 'unknown-error',
	];

	private const REFRESH_OUTCOMES = [
		'not-attempted', 'success', 'failed', 'session-dead', 'timeout',
	];

	private const UUID_PATTERN = '/\A[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/D';

	private function __construct(private readonly array $data)
	{
	}

	public static function fromArray(array $data): self
	{
		$keys = array_keys($data);
		$expected = self::FIELDS;
		sort($keys);
		sort($expected);
		if ($keys !== $expected)
		{
			throw new \InvalidArgumentException('Invalid editor failure report fields.');
		}

		if (
			!self::isIntegerInRange($data['document_session_id'], 1, 2147483647)
			|| !self::isUuid($data['report_attempt_id'])
			|| !in_array($data['attempt_id_source'], ['platform', 'portal'], true)
			|| ($data['load_attempt_id'] !== null && !self::isUuid($data['load_attempt_id']))
			|| !in_array($data['diagnostic_completeness'], ['full', 'partial'], true)
			|| !in_array($data['failure_source'], ['create', 'runtime', 'recovery'], true)
			|| !in_array($data['outcome'], ['failed', 'recovered', 'data-loss'], true)
			|| !self::isNullableIntegerInRange($data['retry_count'], 0, 2147483647)
			|| !self::isNullableEnum($data['code'], self::CODES)
			|| !self::isNullableEnum($data['stage'], [...self::CREATE_STAGES, 'runtime', 'recovery'])
			|| !self::isNullableIntegerInRange($data['attempt'], 1, 2147483647)
			|| !self::isNullableIntegerInRange($data['engineCode'], -2147483648, 2147483647)
			|| !self::isNullableIntegerInRange($data['httpStatus'], 100, 599)
			|| !self::isNullableIntegerInRange($data['elapsed_ms'], 0, 2147483647)
			|| !self::isNullableEnum($data['finalReason'], self::FINAL_REASONS)
			|| !self::isNullableEnum($data['refreshOutcome'], self::REFRESH_OUTCOMES)
		)
		{
			throw new \InvalidArgumentException('Invalid editor failure report value.');
		}

		if (
			($data['attempt_id_source'] === 'portal' && $data['load_attempt_id'] !== null)
			|| ($data['attempt_id_source'] === 'platform' && (
				$data['load_attempt_id'] === null
				|| strcasecmp($data['report_attempt_id'], $data['load_attempt_id']) !== 0
			))
		)
		{
			throw new \InvalidArgumentException('Invalid editor failure attempt identity.');
		}

		if (!self::hasValidCombination($data))
		{
			throw new \InvalidArgumentException('Invalid editor failure report combination.');
		}

		$data['report_attempt_id'] = strtolower($data['report_attempt_id']);
		if ($data['load_attempt_id'] !== null)
		{
			$data['load_attempt_id'] = strtolower($data['load_attempt_id']);
		}

		return new self($data);
	}

	public function get(string $field): mixed
	{
		if (!in_array($field, self::FIELDS, true))
		{
			throw new \InvalidArgumentException('Unknown editor failure report field.');
		}

		return $this->data[$field];
	}

	public function toArray(): array
	{
		return $this->data;
	}

	public function getDeduplicationHash(): string
	{
		$fields = [
			$this->data['report_attempt_id'],
			$this->data['outcome'],
			$this->data['failure_source'],
			$this->data['stage'],
			$this->data['code'],
			$this->data['finalReason'],
			$this->data['engineCode'],
			$this->data['httpStatus'],
		];

		return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
	}

	private static function hasValidCombination(array $data): bool
	{
		$source = $data['failure_source'];
		$outcome = $data['outcome'];
		$code = $data['code'];
		$stage = $data['stage'];
		$reason = $data['finalReason'];
		$retryCount = $data['retry_count'];

		if ($source === 'create')
		{
			if (
				$outcome === 'data-loss'
				|| ($stage !== null && !in_array($stage, self::CREATE_STAGES, true))
			)
			{
				return false;
			}
			$classified = $code !== null && $stage !== null && $reason !== null
				&& $data['refreshOutcome'] !== null;
		}
		elseif ($source === 'runtime')
		{
			if (
				$outcome !== 'failed'
				|| $code !== 'ds-error'
				|| $stage !== 'runtime'
				|| $reason !== 'ds-error'
				|| $data['engineCode'] === -18
				|| $retryCount !== null
				|| !self::hasNullFirstLoadFields($data)
			)
			{
				return false;
			}
			$classified = true;
		}
		else
		{
			if ($stage !== 'recovery' || !self::hasNullFirstLoadFields($data))
			{
				return false;
			}
			if ($outcome === 'data-loss')
			{
				if ($code !== 'reopened-unsaved-lost' || $reason !== $code)
				{
					return false;
				}
			}
			elseif (
				($data['engineCode'] !== -18
					&& !($code === 'ds-error' && $data['engineCode'] !== null)
					&& !($data['engineCode'] === null
						&& in_array($code, self::RECOVERY_CODES_WITHOUT_ENGINE, true)))
				|| $code !== $reason
			)
			{
				return false;
			}
			$classified = $code !== null && $reason !== null;
		}

		if ($outcome === 'recovered')
		{
			$classified = $classified && $retryCount !== null;
		}

		return ($data['diagnostic_completeness'] === 'full') === $classified;
	}

	private static function hasNullFirstLoadFields(array $data): bool
	{
		return $data['attempt'] === null
			&& $data['elapsed_ms'] === null
			&& $data['refreshOutcome'] === null;
	}

	private static function isUuid(mixed $value): bool
	{
		return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1;
	}

	private static function isNullableEnum(mixed $value, array $values): bool
	{
		return $value === null || in_array($value, $values, true);
	}

	private static function isIntegerInRange(mixed $value, int $min, int $max): bool
	{
		return is_int($value) && $value >= $min && $value <= $max;
	}

	private static function isNullableIntegerInRange(mixed $value, int $min, int $max): bool
	{
		return $value === null || self::isIntegerInRange($value, $min, $max);
	}
}

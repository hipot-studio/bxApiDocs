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

	public const ENGINE_ERROR_ORIGINS = ['server_open_error', 'result_signature_mismatch'];

	private const RISK_FIELDS = [
		'risk_reason', 'recovery_trigger', 'reopen_reason', 'previous_editor_key', 'editor_key',
		'unsaved_changes', 'disconnected', 'offline',
	];

	private const REFRESH_FIELDS = [
		'refresh_response_code', 'refresh_retry_after_present', 'refresh_last_http_status',
		'refresh_request_trace_id', 'refresh_request_span_id', 'helper_version', 'platform_doc_id', 'platform_session_id',
	];

	private const OPTIONAL_FIELDS = [...self::RISK_FIELDS, ...self::REFRESH_FIELDS, 'diagnostics', 'engineErrorOrigin'];

	private const CODES = [
		'api-js-load-failed', 'editor-create-failed', 'invalid-open-config', 'refresh-fetch-failed',
		'session-dead', 'recovery-failed', 'ds-error', 'save-refused', 'no-capacity',
		'reopened-unsaved-lost', 'reopened-unsaved-risk',
	];

	private const RECOVERY_CODES_WITHOUT_ENGINE = [
		'refresh-fetch-failed', 'save-refused', 'no-capacity', 'recovery-failed',
		'editor-create-failed', 'api-js-load-failed', 'invalid-open-config', 'ds-error',
	];

	private const CREATE_STAGES = [
		'api-create', 'ready', 'document-ready', 'refresh', 'validate-config', 'apply-config', 'unknown',
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
		$keys = array_keys(array_diff_key($data, array_fill_keys(self::OPTIONAL_FIELDS, true)));
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
			|| !in_array($data['outcome'], ['failed', 'recovered', 'data-loss', 'data-loss-risk', 'observed'], true)
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

		if (array_key_exists('engineErrorOrigin', $data) && (
			$data['failure_source'] !== 'create'
			|| $data['outcome'] !== 'failed'
			|| $data['code'] !== 'ds-error'
			|| $data['engineCode'] !== -85
			|| !in_array($data['engineErrorOrigin'], self::ENGINE_ERROR_ORIGINS, true)
		))
		{
			throw new \InvalidArgumentException('Invalid editor failure report origin.');
		}

		if (
			!self::hasValidDiagnostics($data)
			|| !self::hasValidRiskFields($data)
			|| !self::hasValidRefreshFields($data)
			|| !self::hasValidCombination($data)
		)
		{
			throw new \InvalidArgumentException('Invalid editor failure report combination.');
		}

		$data['report_attempt_id'] = strtolower($data['report_attempt_id']);
		if ($data['load_attempt_id'] !== null)
		{
			$data['load_attempt_id'] = strtolower($data['load_attempt_id']);
		}

		if ($data['outcome'] === 'data-loss')
		{
			$data['outcome'] = 'data-loss-risk';
			$data['code'] = 'reopened-unsaved-risk';
			$data['finalReason'] = $data['code'];
			$data['risk_reason'] = 'unknown';
		}

		return new self($data);
	}

	public function get(string $field): mixed
	{
		if (!in_array($field, [...self::FIELDS, ...self::OPTIONAL_FIELDS], true))
		{
			throw new \InvalidArgumentException('Unknown editor failure report field.');
		}

		return $this->data[$field] ?? null;
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

		if (isset($this->data['engineErrorOrigin']))
		{
			$fields[] = $this->data['engineErrorOrigin'];
		}

		if ($this->data['outcome'] === 'data-loss-risk')
		{
			foreach (self::RISK_FIELDS as $field)
			{
				$fields[] = $this->data[$field] ?? null;
			}
		}
		$transportFacts = [
			$this->data['refresh_response_code'] ?? null,
			$this->data['refresh_retry_after_present'] ?? null,
			$this->data['refresh_last_http_status'] ?? null,
		];
		if ($transportFacts !== [null, null, null])
		{
			array_push($fields, ...$transportFacts);
		}

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

		if ($outcome === 'observed')
		{
			return $source === 'create' && $data['diagnostic_completeness'] === 'partial'
				&& in_array($stage, self::CREATE_STAGES, true)
				&& $data['attempt'] !== null && $data['elapsed_ms'] !== null
				&& $code === null && $reason === null && $retryCount === null
				&& $data['engineCode'] === null && $data['httpStatus'] === null && $data['refreshOutcome'] === null;
		}

		if ($source === 'create')
		{
			if (
				in_array($outcome, ['data-loss', 'data-loss-risk'], true)
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
			if (in_array($outcome, ['data-loss', 'data-loss-risk'], true))
			{
				$expectedCode = $outcome === 'data-loss' ? 'reopened-unsaved-lost' : 'reopened-unsaved-risk';
				if ($code !== $expectedCode || $reason !== $code)
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

	private static function hasValidDiagnostics(array $data): bool
	{
		if (!array_key_exists('diagnostics', $data))
		{
			return $data['outcome'] !== 'observed';
		}
		if ($data['failure_source'] !== 'create' || !is_array($data['diagnostics']))
		{
			return false;
		}
		$diagnostics = FirstLoadDiagnosticReport::fromArray($data['diagnostics'])->toArray();
		return match ($data['outcome'])
		{
			'failed' => $diagnostics['trigger'] === 'terminal',
			'recovered' => $diagnostics['trigger'] === 'recovered',
			'observed' => !$diagnostics['documentReadyReached'] && (
				($diagnostics['trigger'] === 'first_failure' && $diagnostics['initialFailure'] !== null)
				|| ($diagnostics['trigger'] === 'no_signal' && $diagnostics['appReadyReached'])
			),
			default => false,
		};
	}

	private static function hasValidRiskFields(array $data): bool
	{
		$riskFields = array_intersect_key($data, array_fill_keys(self::RISK_FIELDS, true));
		if ($data['outcome'] !== 'data-loss-risk')
		{
			return $riskFields === [];
		}
		if (!in_array($data['risk_reason'] ?? null, ['unknown', 'connection-loss', 'unconfirmed-changes'], true))
		{
			return false;
		}
		if (
			!self::isNullableEnum($data['recovery_trigger'] ?? null, [
				'engine-disconnect', 'session-refresh', 'hello-mismatch', 'ds-request', 'network-restored',
			])
			|| !self::isNullableEnum($data['reopen_reason'] ?? null, ['reopen', 'failover', 'rebalance'])
		)
		{
			return false;
		}
		foreach (['previous_editor_key', 'editor_key'] as $field)
		{
			$value = $data[$field] ?? null;
			if ($value !== null && (!is_string($value) || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $value) !== 1))
			{
				return false;
			}
		}
		foreach (['unsaved_changes', 'disconnected', 'offline'] as $field)
		{
			if (($data[$field] ?? null) !== null && !is_bool($data[$field]))
			{
				return false;
			}
		}

		return match ($data['risk_reason'])
		{
			'unconfirmed-changes' => ($data['unsaved_changes'] ?? null) === true,
			'connection-loss' => ($data['unsaved_changes'] ?? null) === false
				&& (($data['disconnected'] ?? null) === true || ($data['offline'] ?? null) === true),
			default => ($data['unsaved_changes'] ?? null) === null
				&& ($data['disconnected'] ?? null) === null && ($data['offline'] ?? null) === null,
		};
	}

	private static function hasNullFirstLoadFields(array $data): bool
	{
		return $data['attempt'] === null
			&& $data['elapsed_ms'] === null
			&& $data['refreshOutcome'] === null;
	}

	private static function hasValidRefreshFields(array $data): bool
	{
		if (
			!self::isNullableEnum($data['refresh_response_code'] ?? null, [
				'not_active', 'saving_in_progress', 'save_refused', 'no_capacity',
			])
			|| !self::isNullableIntegerInRange($data['refresh_last_http_status'] ?? null, 100, 599)
			|| (($data['refresh_retry_after_present'] ?? null) !== null
				&& !is_bool($data['refresh_retry_after_present']))
		)
		{
			return false;
		}
		foreach (['platform_doc_id', 'platform_session_id'] as $field)
		{
			if (($data[$field] ?? null) !== null && !self::isUuid($data[$field]))
			{
				return false;
			}
		}
		foreach ([
			'refresh_request_trace_id' => '/\A(?!0{32}\z)[a-f0-9]{32}\z/D',
			'refresh_request_span_id' => '/\A(?!0{16}\z)[a-f0-9]{16}\z/D',
			'helper_version' => '/\A[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}\+[a-f0-9]{64}\z/D',
		] as $field => $pattern)
		{
			$value = $data[$field] ?? null;
			if ($value !== null && (!is_string($value) || preg_match($pattern, $value) !== 1))
			{
				return false;
			}
		}

		return true;
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

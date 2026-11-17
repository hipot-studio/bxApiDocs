<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Disk\Document\Vibeoffice\OpenFailureCode;

final class SafeEventFormatter
{
	private const EDITOR_FIELDS = [
		'document_session_id', 'report_attempt_id', 'attempt_id_source', 'load_attempt_id',
		'diagnostic_completeness', 'failure_source', 'outcome', 'retry_count', 'code', 'stage',
		'attempt', 'engineCode', 'httpStatus', 'elapsed_ms', 'finalReason', 'refreshOutcome', 'user_id',
	];

	private const EDITOR_CODES = [
		'api-js-load-failed', 'editor-create-failed', 'invalid-open-config', 'refresh-fetch-failed',
		'session-dead', 'recovery-failed', 'ds-error', 'save-refused', 'no-capacity',
		'reopened-unsaved-lost',
	];

	private const FIELDS = [
		'vibeoffice open' => [
			'result', 'code', 'attempts', 'document_session_id', 'session_id',
			'load_attempt_id', 'trace_id', 'user_id', 'duration_ms',
			'report_attempt_id', 'attempt_id_source',
		],
		'vibeoffice editor failure' => self::EDITOR_FIELDS,
		'vibeoffice editor recovered' => self::EDITOR_FIELDS,
		'vibeoffice webhook received' => [
			'delivery_id', 'event', 'result', 'code', 'delivery_outcome',
			'httpStatus', 'duration_ms',
		],
		'vibeoffice webhook diagnostic' => [
			'operation', 'code', 'exception_class', 'delivery_id', 'event',
		],
		'vibeoffice platform request' => [
			'method', 'path', 'httpStatus', 'platformCode', 'duration_ms',
			'attempt', 'result', 'code', 'request_sent', 'trace_id',
		],
		'vibeoffice state' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice finalizer' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice component' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice maintenance' => [
			'operation', 'result', 'code', 'exception_class', 'entity', 'id', 'file', 'delivery',
			'mode', 'busy', 'complete', 'elapsedSeconds',
			'deleted_pending', 'deleted_terminated', 'deleted_deliveries', 'deleted_files', 'deleted_detached',
			'skipped_busyDocuments', 'skipped_referenced', 'skipped_openTransaction', 'skipped_missingDocumentKey',
			'skipped_invalidStagedFile', 'skipped_refusedDeletes', 'skipped_openObligations', 'skipped_openObligationBytes',
			'categories_pending', 'categories_terminated', 'categories_stagedFiles', 'categories_deliveries',
		],
	];

	private const PLATFORM_CODES = [
		'save_refused', 'doc_full', 'file_too_large', 'filetype_not_editable',
		'unsupported_filetype', 'no_capacity', 'saving_in_progress',
		'session_closing', 'open_in_progress', 'failover_in_progress', 'open_session_closed',
	];

	private const CODES_BY_EVENT = [
		'vibeoffice open' => [
			'feature_disabled', 'component_error_template', 'session_nonactive', 'restriction_limit',
			'vibeoffice_open_deadline', 'open_access_revoked', 'open_identity_mismatch',
			'vibeoffice_session_closed', 'open_failed', 'open_exception',
			'open_load_refused', 'open_prefilter_refused', 'handler_not_configured',
			'open_session_missing', 'open_refresh_failed',
		],
		'vibeoffice webhook received' => [
			'bad_signature', 'invalid_http_method', 'invalid_content_type',
			'invalid_delivery', 'invalid_payload', 'vibeoffice_saved_not_applied',
			'duplicate', 'ignored', 'webhook_failed', 'webhook_exception',
		],
		'vibeoffice webhook diagnostic' => [
			'permanent_refusal', 'session_missing', 'file_missing', 'file_too_large',
			'save_refused', 'ignored', 'unsupported_event', 'delivery_exception',
		],
		'vibeoffice platform request' => [
			'vibeoffice_open_deadline', 'vibeoffice_deadline_transport', 'request_encode_failed',
			'vibeoffice_transport_unknown', 'platform_refused', 'response_invalid',
			'protocol_exception',
		],
		'vibeoffice state' => ['state_failed', 'state_exception'],
		'vibeoffice finalizer' => ['finalizer_failed', 'finalizer_exception'],
		'vibeoffice component' => ['component_failed', 'component_exception', 'view_sync_failed'],
		'vibeoffice maintenance' => ['maintenance_failed', 'maintenance_exception'],
	];

	private const PATHS = [
		'/v1/documents/{docKey}/open',
		'/v1/documents/{docKey}/grants',
		'/v1/documents/{docKey}/grants/{userId}',
		'/v1/documents/{docKey}/forcesave',
		'/v1/documents/{docKey}/meta',
		'/v1/convert',
		'/v1/documents/{docKey}/versions/sign-urls',
	];

	public function format(string $event, array $fields): string
	{
		$known = isset(self::FIELDS[$event]);
		$event = $known ? $event : 'vibeoffice unknown';
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]{1,128}$/D', $host) !== 1)
		{
			$host = 'unknown';
		}

		$level = $fields['level'] ?? null;
		if (!in_array($level, ['info', 'warning', 'error'], true))
		{
			$level = 'error';
		}

		$parts = [$event, 'level=' . $level];
		foreach (self::FIELDS[$event] ?? [] as $name)
		{
			$parts[] = $name . '=' . $this->renderField($event, $name, $fields[$name] ?? null, $fields);
		}

		$lines = [
			'Host: ' . $host,
			'Date: ' . date('Y-m-d H:i:s'),
			'Module: disk',
			implode(' ', $parts),
		];
		$trace = in_array($event, ['vibeoffice editor failure', 'vibeoffice editor recovered'], true)
			? ''
			: $this->formatTrace($fields['trace'] ?? null);
		if ($trace !== '')
		{
			$lines[] = $trace;
		}
		$lines[] = '----------';

		return implode("\n", $lines) . "\n";
	}

	private function renderField(string $event, string $name, mixed $value, array $fields): string
	{
		if ($event === 'vibeoffice editor failure' || $event === 'vibeoffice editor recovered')
		{
			return $this->renderEditorField($name, $value);
		}
		if ($event === 'vibeoffice maintenance')
		{
			if (str_starts_with($name, 'deleted_') || str_starts_with($name, 'skipped_'))
			{
				return (string)(is_int($value) && $value >= 0 ? $value : 0);
			}
			if (str_starts_with($name, 'categories_') || in_array($name, ['busy', 'complete'], true))
			{
				return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
			}
			if ($name === 'elapsedSeconds')
			{
				return (string)((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0
					? $value : 0);
			}
			if (in_array($name, ['mode', 'entity'], true))
			{
				$values = $name === 'mode' ? ['off', 'apply', 'unknown'] : ['session', 'delivery'];
				return is_string($value) && in_array($value, $values, true) ? $value : 'null';
			}
		}
		if ($name === 'code')
		{
			return $this->renderCode($event, $value, $fields['result'] ?? null);
		}
		if ($name === 'platformCode')
		{
			return is_string($value) && in_array($value, self::PLATFORM_CODES, true)
				? $this->quote($value)
				: 'null';
		}
		if (in_array($name, ['attempts', 'attempt', 'duration_ms'], true))
		{
			return (string)(is_int($value) && $value >= 0 ? $value : 0);
		}
		if (in_array($name, ['document_session_id', 'delivery_id', 'user_id', 'httpStatus', 'id', 'file', 'delivery'], true))
		{
			if ($name === 'httpStatus')
			{
				return is_int($value) && $value >= 100 && $value <= 599 ? (string)$value : 'null';
			}
			return is_int($value) && $value > 0 ? (string)$value : 'null';
		}
		if ($name === 'request_sent')
		{
			return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
		}
		if ($name === 'session_id')
		{
			return is_string($value)
				&& preg_match('/^[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}$/D', $value) === 1
					? $this->quote($value)
					: 'null';
		}
		if ($name === 'report_attempt_id')
		{
			return is_string($value)
				&& preg_match('/\A[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/D', $value) === 1
					? $this->quote(strtolower($value))
					: 'null';
		}
		if ($name === 'attempt_id_source')
		{
			return is_string($value) && in_array($value, ['platform', 'portal'], true)
				? $this->quote($value)
				: 'null';
		}
		if (in_array($name, ['trace_id', 'load_attempt_id'], true))
		{
			return is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $value) === 1
				? $this->quote($value)
				: 'null';
		}
		if ($name === 'method')
		{
			return is_string($value) && in_array($value, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
				? $value
				: 'unknown';
		}
		if ($name === 'path')
		{
			return is_string($value) && in_array($value, self::PATHS, true) ? $this->quote($value) : 'null';
		}
		if ($name === 'event')
		{
			if ($value === null)
			{
				return 'null';
			}
			return $this->quote(in_array($value, [
				'document.saved', 'document.failover', 'document.session-closed',
				'document.saved:refused',
			], true) ? $value : 'unknown');
		}
		if ($name === 'result')
		{
			$values = match ($event)
			{
				'vibeoffice webhook received' => ['applied', 'duplicate', 'bad_signature', 'refused', 'error', 'ignored'],
				'vibeoffice platform request' => ['ok', 'refused', 'error', 'not_sent'],
				default => ['ok', 'refused', 'error'],
			};
			return is_string($value) && in_array($value, $values, true) ? $value : 'error';
		}
		if ($name === 'delivery_outcome')
		{
			return is_string($value) && in_array($value, ['applied', 'refused', 'ignored'], true)
				? $value
				: 'null';
		}
		if ($name === 'exception_class')
		{
			return is_string($value) && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,127}$/D', $value) === 1
				? $this->quote($value)
				: 'null';
		}
		if ($name === 'operation')
		{
			if ($event === 'vibeoffice maintenance')
			{
				return is_string($value) && in_array($value, ['run', 'cleanup', 'delete', 'clear_stage', 'pass'], true)
					? $value : 'unknown';
			}
			if ($event === 'vibeoffice component' && $value === 'view_sync')
			{
				return $value;
			}
			if ($event === 'vibeoffice webhook diagnostic')
			{
				return is_string($value) && in_array($value, ['acknowledge', 'dispatch', 'unsupported'], true)
					? $value
					: 'unknown';
			}
			return is_string($value) && in_array($value, [
				'open', 'save', 'finalize', 'publish', 'state', 'component', 'webhook',
			], true) ? $value : 'unknown';
		}

		return 'null';
	}

	private function renderEditorField(string $name, mixed $value): string
	{
		if ($value === null)
		{
			return 'null';
		}
		if (in_array($name, ['document_session_id', 'user_id', 'attempt'], true))
		{
			return is_int($value) && $value > 0 && $value <= 2147483647 ? (string)$value : 'null';
		}
		if (in_array($name, ['retry_count', 'elapsed_ms'], true))
		{
			return is_int($value) && $value >= 0 && $value <= 2147483647 ? (string)$value : 'null';
		}
		if ($name === 'engineCode')
		{
			return is_int($value) && $value >= -2147483648 && $value <= 2147483647
				? (string)$value : 'null';
		}
		if ($name === 'httpStatus')
		{
			return is_int($value) && $value >= 100 && $value <= 599 ? (string)$value : 'null';
		}
		if ($name === 'report_attempt_id' || $name === 'load_attempt_id')
		{
			return is_string($value)
				&& preg_match('/\A[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/D', $value) === 1
					? $this->quote(strtolower($value)) : 'null';
		}

		$allowed = match ($name)
		{
			'attempt_id_source' => ['platform', 'portal'],
			'diagnostic_completeness' => ['full', 'partial'],
			'failure_source' => ['create', 'runtime', 'recovery'],
			'outcome' => ['failed', 'recovered', 'data-loss'],
			'code' => self::EDITOR_CODES,
			'stage' => [
				'api-create', 'ready', 'document-ready', 'refresh', 'validate-config', 'apply-config',
				'runtime', 'recovery',
			],
			'finalReason' => [...self::EDITOR_CODES, 'app-ready-missing', 'timeout', 'unknown-error'],
			'refreshOutcome' => ['not-attempted', 'success', 'failed', 'session-dead', 'timeout'],
			default => [],
		};

		return is_string($value) && in_array($value, $allowed, true) ? $this->quote($value) : 'null';
	}

	private function renderCode(string $event, mixed $value, mixed $result): string
	{
		if (($event === 'vibeoffice webhook received' && $result === 'applied')
			|| ($event === 'vibeoffice open' && $result === 'ok')
			|| ($event === 'vibeoffice maintenance' && $result === 'ok')
			|| ($event === 'vibeoffice platform request' && $result === 'ok'))
		{
			return 'null';
		}
		if ($event === 'vibeoffice webhook received'
			&& in_array($result, ['duplicate', 'ignored', 'bad_signature'], true))
		{
			return $result;
		}
		if (is_string($value) && (in_array($value, self::CODES_BY_EVENT[$event] ?? [], true)
			|| ($event === 'vibeoffice open' && $this->isOpenFailureCode($value))))
		{
			return $value;
		}
		if ($event === 'vibeoffice webhook diagnostic')
		{
			return 'null';
		}
		return match ($event)
		{
			'vibeoffice open' => 'open_failed',
			'vibeoffice webhook received' => 'webhook_failed',
			'vibeoffice platform request' => $result === 'refused' ? 'platform_refused' : 'protocol_exception',
			'vibeoffice state' => 'state_failed',
			'vibeoffice finalizer' => 'finalizer_failed',
			'vibeoffice maintenance' => 'maintenance_failed',
			default => 'component_failed',
		};
	}

	private function isOpenFailureCode(string $value): bool
	{
		foreach (OpenFailureCode::cases() as $case)
		{
			if ($case->value === $value)
			{
				return true;
			}
		}
		return false;
	}

	private function quote(string $value): string
	{
		$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		$value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
		$value = preg_replace('/\s+/u', ' ', $value) ?? '';
		$value = mb_strcut(trim($value), 0, 128, 'UTF-8');

		return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
	}

	private function formatTrace(mixed $trace): string
	{
		if (!is_array($trace))
		{
			return '';
		}
		$lines = [];
		foreach (array_slice($trace, 0, 10) as $index => $frame)
		{
			if (!is_array($frame))
			{
				continue;
			}
			$class = $this->safeSymbol($frame['class'] ?? null);
			$function = $this->safeSymbol($frame['function'] ?? null);
			$file = $this->safeFile($frame['file'] ?? null);
			$line = is_int($frame['line'] ?? null) && $frame['line'] > 0 ? $frame['line'] : 0;
			$lines[] = '#' . $index . ' ' . $class . '::' . $function . ' ' . $file . ':' . $line;
		}
		return mb_strcut(implode("\n", $lines), 0, 2048, 'UTF-8');
	}

	private function safeSymbol(mixed $value): string
	{
		return is_string($value) && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,127}$/D', $value) === 1
			? $value
			: 'unknown';
	}

	private function safeFile(mixed $value): string
	{
		if (!is_string($value))
		{
			return 'unknown';
		}
		$base = basename($value);
		return preg_match('/^[A-Za-z0-9_.-]{1,64}\.php$/D', $base) === 1 ? $base : 'unknown';
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Disk\Document\Vibeoffice\EditorFailureReport;

use Bitrix\Disk\Document\Vibeoffice\OpenAttemptState;
use Bitrix\Disk\Document\Vibeoffice\OpenFailureCode;
use Bitrix\Disk\Document\Vibeoffice\FirstLoadDiagnosticReport;
use Bitrix\Main\Config\Configuration;

final class SafeEventFormatter
{
	private const EDITOR_FIELDS = [
		'document_session_id', 'report_attempt_id', 'attempt_id_source', 'load_attempt_id',
		'diagnostic_completeness', 'failure_source', 'outcome', 'retry_count', 'code', 'stage',
		'attempt', 'engineCode', 'httpStatus', 'elapsed_ms', 'finalReason', 'refreshOutcome', 'user_id',
		'risk_reason', 'recovery_trigger', 'reopen_reason', 'previous_editor_key_hash', 'editor_key_hash',
		'unsaved_changes', 'disconnected', 'offline',
		'document_correlation_id', 'diagnostics',
		'refresh_response_code', 'refresh_retry_after_present', 'refresh_last_http_status',
		'refresh_request_trace_id', 'refresh_request_span_id', 'helper_version', 'platform_doc_id', 'platform_session_id',
	];

	private const EDITOR_CODES = [
		'api-js-load-failed', 'editor-create-failed', 'invalid-open-config', 'refresh-fetch-failed',
		'session-dead', 'recovery-failed', 'ds-error', 'save-refused', 'no-capacity',
		'reopened-unsaved-lost', 'reopened-unsaved-risk',
	];

	private const OPEN_REFUSAL_FIELDS = [
		'failure_stage', 'failure_reason', 'session_can_read', 'session_can_edit',
		'attachment_file_matches', 'attachment_covers_version', 'direct_can_read',
		'registry_reason', 'registry_status', 'registry_outstanding',
		'registry_fingerprint_present', 'registry_fingerprint_matches',
	];

	private const OPEN_FALLBACK_FIELDS = [
		'fallback_reason', 'fallback_source_session_id', 'fallback_view_session_id',
		'fallback_view_attempts', 'fallback_cleanup_ok',
	];

	private const OPEN_REGISTRY_REASONS = [
		'token_expired', 'token_age_unknown', 'fingerprint_mismatch', 'attempt_state_unknown', 'attempt_stopped',
		'outstanding_limit_reached', 'attempt_not_openable', 'session_inactive', 'registry_write_failed',
		'context_lock_failed', 'context_unavailable', 'registry_transition_failed',
		'document_lock_timeout', 'registry_busy', 'attempt_unresolved', 'registry_failed',
	];

	private const FIELDS = [
		'vibeoffice open' => [
			'result', 'code', 'attempts', 'document_session_id', 'session_id',
			'load_attempt_id', 'trace_id', 'user_id', 'duration_ms',
			'report_attempt_id', 'attempt_id_source', 'document_correlation_id',
			...self::OPEN_REFUSAL_FIELDS,
			...self::OPEN_FALLBACK_FIELDS,
			'closed_recovery_outstanding',
		],
		'vibeoffice editor failure' => self::EDITOR_FIELDS,
		'vibeoffice editor recovered' => self::EDITOR_FIELDS,
		'vibeoffice editor recovery risk' => self::EDITOR_FIELDS,
		'vibeoffice editor loading diagnostic' => self::EDITOR_FIELDS,
		'vibeoffice webhook received' => [
			'delivery_id', 'event', 'result', 'code', 'delivery_outcome', 'source',
			'httpStatus', 'duration_ms', 'document_correlation_id',
		],
		'vibeoffice webhook diagnostic' => [
			'operation', 'code', 'exception_class', 'delivery_id', 'event',
			'document_correlation_id', 'stage', 'reason_code', 'refusal_check',
			'httpStatus', 'signature_version', 'sql_state', 'db_error_code', 'db_table', 'db_column', 'db_error_kind',
			'source_object_id', 'result_object_id', 'source_found', 'result_found', 'source_id_valid',
			'missing_object', 'result_kind', 'delivery_status', 'delivery_outcome', 'delivery_notified',
			'result_version_id', 'snapshot_seq', 'scope_session_type', 'scope_version_id', 'scope_external_link_id',
			'generation_rev', 'generation_correlation_id', 'scope_generation_present',
			'owner_candidates', 'owner_candidates_truncated', 'candidates_total_examined',
		],
		'vibeoffice platform request' => [
			'method', 'path', 'httpStatus', 'platformCode', 'duration_ms',
			'attempt', 'result', 'code', 'request_sent', 'trace_id', 'document_correlation_id',
			'request_id', 'transport_kind', 'transport_error', 'transport_errno', 'transport_framework_code',
			'response_headers_received', 'observed_http_status', 'opening_budget_ms', 'opening_budget_expired',
			'transport_dns_ms', 'transport_connect_ms', 'transport_tls_ms', 'transport_first_byte_ms',
			'conflict_code_state', 'retry_after_s',
		],
		'vibeoffice state' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice finalizer' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice component' => ['operation', 'code', 'document_session_id', 'exception_class'],
		'vibeoffice maintenance' => [
			'operation', 'result', 'code', 'exception_class', 'entity', 'id', 'file', 'delivery',
			'stop_reason', 'stop_category',
			'mode', 'busy', 'complete', 'elapsedSeconds',
			'pendingCount', 'dueExpiryCount', 'dueRetryCount', 'oldestDueAge', 'unknownAgeCount',
			'metricsComplete', 'countsAreLowerBounds', 'profileExceeded',
			'pendingCountLowerBound', 'dueExpiryCountLowerBound', 'dueRetryCountLowerBound',
			'unknownAgeCountLowerBound', 'oldestDueAgeLowerBound',
			'markedCount', 'confirmedCount', 'latencySampleCount', 'completeRevokeLatency',
			'arrivalRate', 'drainRate', 'rateScope', 'passSeconds',
			'deleted_pending', 'deleted_terminated', 'deleted_refused',
			'deleted_deliveries', 'deleted_files', 'deleted_detached',
			'deleted_nonActiveSessions', 'deleted_snapshots', 'deleted_states',
			'skipped_busyDocuments', 'skipped_referenced', 'skipped_openTransaction', 'skipped_missingDocumentKey',
			'skipped_invalidStagedFile', 'skipped_refusedDeletes', 'skipped_openObligations', 'skipped_openObligationBytes',
			'skipped_deferredDocuments',
			'categories_pending', 'categories_terminated', 'categories_refused',
			'categories_stagedFiles', 'categories_deliveries',
			'categories_expiredDocuments',
		],
	];

	private const PLATFORM_CODES = [
		'save_refused', 'doc_full', 'file_too_large', 'filetype_not_editable',
		'unsupported_filetype', 'no_capacity', 'saving_in_progress',
		'session_closing', 'open_in_progress', 'failover_in_progress', 'open_session_closed',
		'not_active', 'no_editor_session', 'editor_key_mismatch', 'open_identity_mismatch', 'stale_attempt',
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
			'save_refused', 'ignored', 'unsupported_event', 'delivery_exception', 'notification_skipped',
			'webhook_policy_unavailable',
		],
		'vibeoffice platform request' => [
			'vibeoffice_open_deadline', 'vibeoffice_deadline_transport', 'request_encode_failed',
			'vibeoffice_transport_unknown', 'platform_refused', 'response_invalid',
			'protocol_exception',
		],
		'vibeoffice state' => ['state_failed', 'state_exception'],
		'vibeoffice finalizer' => ['finalizer_failed', 'finalizer_exception'],
		'vibeoffice component' => ['component_failed', 'component_exception', 'view_sync_failed'],
		'vibeoffice maintenance' => [
			'maintenance_failed', 'maintenance_exception', 'maintenance_time_limit', 'maintenance_busy',
			'shared_access_profile_exceeded', 'shared_gc_history_index_missing',
		],
	];

	private const WEBHOOK_STAGES = [
		'validation', 'ledger', 'scope', 'download', 'stage_file', 'write_version', 'finalize', 'notify', 'dispatch',
		'policy',
	];

	private const WEBHOOK_REASONS = [
		'validation_failed', 'url_rejected', 'download_failed', 'downloaded_file_unreadable',
		'file_too_large', 'format_restricted', 'quota_exceeded', 'state_missing', 'session_missing', 'file_missing',
		'scope_unwritable', 'read_only_scope', 'staged_file_missing', 'owner_missing',
		'snapshot_identity_mismatch', 'lock_timeout',
		'invalid_object_id',
		'stage_file_failed', 'version_write_failed', 'ledger_write_failed', 'finalization_failed',
		'notification_failed', 'notification_result_deleted', 'model_reason_missing', 'unknown_failure',
		'policy_db_error', 'policy_binding_missing', 'policy_binding_invalid', 'policy_row_missing',
		'policy_row_invalid', 'policy_fence_invalid', 'policy_transaction_active', 'db_error',
	];

	private const MAX_OWNER_CANDIDATES = 20;
	private const MAX_CANDIDATES_EXAMINED = 1000000;
	private const DIAGNOSTIC_ENUMS = [
		'missing_object' => ['source', 'result', 'both', 'unknown', 'none'],
		'result_kind' => ['original', 'newversion', 'conflictcopy', 'unknown'],
		'delivery_status' => ['pending', 'staged', 'applied', 'done'],
		'refusal_check' => [
			'ack_not_negotiated', 'invalid_payload', 'legacy_payload', 'unknown_snapshot_identity',
			'invalid_snapshot_seq', 'edit_fence_blocked', 'invalid_delivery_id', 'transaction_active',
			'scope_mismatch', 'object_exists', 'delivery_missing', 'delivery_mismatch', 'delivery_status_mismatch',
			'delivery_has_result', 'snapshot_not_newer', 'snapshot_receipt_exists', 'ledger_write_failed',
			'transaction_failed', 'state_missing', 'lock_timeout',
		],
	];
	private const OWNER_CANDIDATE_FIELDS = [
		'source', 'candidate_id', 'session_id', 'existence', 'eligibility', 'file_read', 'session_read', 'rejection',
	];
	private const OWNER_CANDIDATE_ENUMS = [
		'source' => ['session_owner', 'session_user', 'file_created_by'],
		'existence' => ['present', 'missing', 'not_checked'],
		'eligibility' => ['not_checked'],
		'file_read' => ['allowed', 'denied', 'not_checked'],
		'session_read' => ['allowed', 'denied', 'not_checked'],
		'rejection' => [
			'invalid_id', 'user_missing', 'read_denied', 'not_checked', 'not_evaluated_by_algorithm', 'none',
		],
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

	private function hasPartialMaintenanceCounts(array $fields): bool
	{
		if (($fields['operation'] ?? null) !== 'shared_pass' || ($fields['result'] ?? null) !== 'incomplete'
			|| ($fields['code'] ?? null) !== 'shared_access_profile_exceeded'
			|| ($fields['metricsComplete'] ?? null) !== false || ($fields['countsAreLowerBounds'] ?? null) !== true
			|| ($fields['profileExceeded'] ?? null) !== true) { return false; }
		foreach (['pendingCount', 'dueExpiryCount', 'dueRetryCount', 'unknownAgeCount'] as $name)
		{
			$bound = $fields[$name . 'LowerBound'] ?? null;
			if (!is_int($bound) || $bound < 0) { return false; }
		}
		return true;
	}

	public function format(string $event, array $fields): string
	{
		$instant = new \DateTimeImmutable();
		if (in_array($event, [
			'vibeoffice editor failure', 'vibeoffice editor recovered', 'vibeoffice editor recovery risk',
			'vibeoffice editor loading diagnostic',
		], true))
		{
			foreach (['previous_editor_key', 'editor_key'] as $field)
			{
				$value = $fields[$field] ?? null;
				$fields[$field . '_hash'] = is_string($value)
					&& preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $value) === 1
						? hash('sha256', $value) : null;
			}
		}

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

		if ($event === 'vibeoffice editor failure' && ($fields['failure_source'] ?? null) === 'create'
			&& ($fields['code'] ?? null) === 'ds-error' && ($fields['engineCode'] ?? null) === -85)
		{
			$origin = $fields['engineErrorOrigin'] ?? null;
			$origin = in_array($origin, EditorFailureReport::ENGINE_ERROR_ORIGINS, true) ? $origin : 'unknown';
			$parts[] = 'engineErrorOrigin=' . $this->quote($origin);
			if ($origin === 'server_open_error')
			{
				$parts[] = 'serverErrorCode=-88';
			}
		}

		if ($event === 'vibeoffice webhook diagnostic')
		{
			array_push($parts, ...$this->formatRuntimeMetadata());
		}

		$parts[] = 'event_time=' . $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');

		$lines = [
			'Host: ' . $host,
			'Date: ' . $instant->format('Y-m-d H:i:s'),
			'Module: disk',
			implode(' ', $parts),
		];
		$trace = in_array($event, [
			'vibeoffice editor failure', 'vibeoffice editor recovered', 'vibeoffice editor recovery risk',
			'vibeoffice editor loading diagnostic',
		], true)
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
		if ($event === 'vibeoffice open' && $name === 'closed_recovery_outstanding')
		{
			return is_int($value) && $value >= 0 && $value <= OpenAttemptState::MAX_OUTSTANDING
				? (string)$value : 'null';
		}

		if ($event === 'vibeoffice open' && in_array($name, self::OPEN_REFUSAL_FIELDS, true))
		{
			return $this->renderOpenRefusalField($name, $value, $fields);
		}
		if ($event === 'vibeoffice open' && in_array($name, self::OPEN_FALLBACK_FIELDS, true))
		{
			return $this->renderOpenFallbackField($name, $value, $fields);
		}
		if ($name === 'diagnostics')
		{
			try
			{
				return is_array($value)
					? json_encode(FirstLoadDiagnosticReport::fromArray($value)->toArray(), JSON_THROW_ON_ERROR)
					: 'null';
			}
			catch (\InvalidArgumentException | \JsonException)
			{
				return 'null';
			}
		}
		if ($event === 'vibeoffice webhook diagnostic')
		{
			$facet = $this->renderDiagnosticFacet($name, $value, $fields);
			if ($facet !== null)
			{
				return $facet;
			}
		}
		if ($event === 'vibeoffice platform request')
		{
			$enums = [
				'conflict_code_state' => ['known', 'missing', 'unrecognized'],
				'transport_kind' => ['curl', 'stream', 'unknown'],
				'transport_error' => [
					'dns', 'connect', 'tls', 'timeout', 'reset', 'read', 'write', 'protocol', 'unknown',
				],
				'transport_framework_code' => [
					'NETWORK', 'URI_SCHEME', 'URI_HOST', 'URI_PUNICODE', 'PRIVATE_IP', 'REDIRECT', 'MULTIPART', 'OTHER',
				],
			];
			if (isset($enums[$name]))
			{
				return is_string($value) && in_array($value, $enums[$name], true) ? $value : 'null';
			}
			if ($name === 'request_id')
			{
				return is_string($value)
					&& preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1
						? $this->quote($value) : 'null';
			}
			if (in_array($name, ['response_headers_received', 'opening_budget_expired'], true))
			{
				return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
			}
			if (in_array($name, [
				'opening_budget_ms', 'transport_dns_ms', 'transport_connect_ms', 'transport_tls_ms',
				'transport_first_byte_ms',
			], true))
			{
				return is_int($value) && $value >= 0 ? (string)min(2147483647, $value) : 'null';
			}
			if ($name === 'transport_errno')
			{
				return is_int($value) && $value >= 1 && $value <= 65535 ? (string)$value : 'null';
			}
			if ($name === 'observed_http_status')
			{
				return is_int($value) && $value >= 100 && $value <= 599 ? (string)$value : 'null';
			}
			if ($name === 'retry_after_s')
			{
				return $this->renderDiagnosticInteger($value, 1, 2147483647);
			}
		}
		if ($name === 'document_correlation_id')
		{
			return is_string($value) && preg_match('/^vod1_[a-f0-9]{64}$/D', $value) === 1
				? $this->quote($value)
				: 'null';
		}
		if ($event === 'vibeoffice webhook diagnostic' && $name === 'stage')
		{
			return is_string($value) && in_array($value, self::WEBHOOK_STAGES, true) ? $value : 'null';
		}
		if ($event === 'vibeoffice webhook diagnostic' && $name === 'reason_code')
		{
			return is_string($value) && in_array($value, self::WEBHOOK_REASONS, true) ? $value : 'unknown_failure';
		}
		if ($event === 'vibeoffice webhook diagnostic')
		{
			$rendered = match ($name)
			{
				'signature_version' => $this->renderDiagnosticInteger($value, 1, 2),
				'sql_state' => is_string($value) && preg_match('/\A[0-9A-Z]{5}\z/', $value) ? $value : 'null',
				'db_error_code' => is_string($value) && $value === ($fields['sql_state'] ?? null)
					&& preg_match('/\A[0-9A-Z]{5}\z/', $value) ? $value
					: $this->renderDiagnosticInteger($value, 1, 999999),
				'db_table' => in_array($value, DatabaseErrorDiagnostic::TABLES, true) ? $value : 'null',
				'db_error_kind' => in_array($value, ['query', 'connection'], true) ? $value : 'null',
				'db_column' => is_string($value) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/', $value)
					? $value : 'null',
				default => null,
			};
			if ($rendered !== null)
			{
				return $rendered;
			}
		}
		if (in_array($event, [
			'vibeoffice editor failure', 'vibeoffice editor recovered', 'vibeoffice editor recovery risk',
			'vibeoffice editor loading diagnostic',
		], true))
		{
			return $this->renderEditorField($name, $value);
		}
		if ($event === 'vibeoffice maintenance')
		{
			if ($name === 'stop_reason' && $value === 'open_transaction'
				&& ($fields['operation'] ?? null) === 'switch_platform')
			{
				return $value;
			}
			if ($name === 'stop_reason' || $name === 'stop_category')
			{
				$values = $name === 'stop_reason'
					? ['completed', 'disabled', 'busy', 'time_limit', 'exception']
					: ['pending', 'terminated', 'refused', 'stagedFiles', 'deliveries', 'expiredDocuments'];

				return is_string($value) && in_array($value, $values, true) ? $value : 'null';
			}
			if (str_starts_with($name, 'deleted_') || str_starts_with($name, 'skipped_'))
			{
				return (string)(is_int($value) && $value >= 0 ? $value : 0);
			}
			if (str_starts_with($name, 'categories_') || in_array($name,
				['busy', 'complete', 'metricsComplete', 'countsAreLowerBounds', 'profileExceeded'], true))
			{
				return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
			}
			if ($name === 'rateScope')
			{
				return $value === 'maintenance_pass' ? $value : 'null';
			}
			if (in_array($name, ['pendingCount', 'dueExpiryCount', 'dueRetryCount', 'unknownAgeCount',
				'markedCount', 'confirmedCount', 'latencySampleCount'], true))
			{
				if ($value === null && in_array($name, ['pendingCount', 'dueExpiryCount', 'dueRetryCount', 'unknownAgeCount'], true)
					&& $this->hasPartialMaintenanceCounts($fields)) { return 'null'; }
				return (string)(is_int($value) && $value >= 0 ? $value : 0);
			}
			if (in_array($name, ['pendingCountLowerBound', 'dueExpiryCountLowerBound', 'dueRetryCountLowerBound',
				'unknownAgeCountLowerBound'], true))
			{
				return is_int($value) && $value >= 0 ? (string)$value : 'null';
			}
			if (in_array($name, ['oldestDueAge', 'oldestDueAgeLowerBound', 'completeRevokeLatency', 'arrivalRate', 'drainRate', 'passSeconds'], true))
			{
				return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0
					? (string)$value : 'null';
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
				'vibeoffice maintenance' => ['ok', 'incomplete', 'busy', 'error'],
				'vibeoffice webhook received' => ['applied', 'duplicate', 'bad_signature', 'refused', 'error', 'ignored'],
				'vibeoffice platform request' => ['ok', 'refused', 'error', 'not_sent'],
				default => ['ok', 'refused', 'error'],
			};
			return is_string($value) && in_array($value, $values, true) ? $value : 'error';
		}
		if ($event === 'vibeoffice webhook received' && $name === 'source')
		{
			return in_array($value, ['current', 'departing'], true) ? $value : 'null';
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
			if ($event === 'vibeoffice state' && $value === 'refuse_open')
			{
				return $value;
			}
			if ($event === 'vibeoffice maintenance')
			{
				return is_string($value)
					&& in_array($value, ['run', 'cleanup', 'delete', 'clear_stage', 'pass', 'shared_pass', 'switch_platform'], true)
					? $value : 'unknown';
			}
			if ($event === 'vibeoffice component' && $value === 'view_sync')
			{
				return $value;
			}
			if ($event === 'vibeoffice webhook diagnostic')
			{
				return in_array($value, ['acknowledge', 'dispatch', 'unsupported', 'saved', 'webhook_policy'], true)
					? $value
					: 'unknown';
			}
			return is_string($value) && in_array($value, [
				'open', 'save', 'finalize', 'publish', 'state', 'component', 'webhook',
			], true) ? $value : 'unknown';
		}

		return 'null';
	}

	private function renderOpenRefusalField(string $name, mixed $value, array $fields): string
	{
		$reasonValue = $fields['failure_reason'] ?? null;
		$reason = is_string($reasonValue) ? OpenRefusalReason::tryFrom($reasonValue) : null;
		if (
			$reason === null
			|| ($fields['failure_stage'] ?? null) !== $reason->stage()
			|| (($fields['attempts'] ?? null) !== 0
				&& (!$this->hasOpenFallback($fields) || ($fields['fallback_view_attempts'] ?? null) !== 0))
			|| !in_array($fields['result'] ?? null, ['refused', 'error'], true)
		)
		{
			return 'null';
		}

		if (str_starts_with($name, 'registry_'))
		{
			if (!in_array($reason, [OpenRefusalReason::RegistrationFailed, OpenRefusalReason::SendAdmissionFailed], true)
				|| !in_array($fields['registry_reason'] ?? null, self::OPEN_REGISTRY_REASONS, true))
			{
				return 'null';
			}

			return match ($name)
			{
				'registry_reason' => $value,
				'registry_status' => in_array($value, ['pending', 'active', 'refused', 'terminated'], true)
					? $value : 'null',
				'registry_outstanding' => is_int($value) && $value >= 0 ? (string)$value : 'null',
				default => is_bool($value) ? ($value ? 'true' : 'false') : 'null',
			};
		}

		return match ($name)
		{
			'failure_stage' => $reason->stage(),
			'failure_reason' => $reason->value,
			default => is_bool($value) ? ($value ? 'true' : 'false') : 'null',
		};
	}

	private function hasOpenFallback(array $fields): bool
	{
		$attempts = $fields['attempts'] ?? null;
		$viewAttempts = $fields['fallback_view_attempts'] ?? null;
		$sourceId = $fields['fallback_source_session_id'] ?? null;

		return in_array($fields['fallback_reason'] ?? null, ['no_capacity', 'doc_full'], true)
			&& is_int($sourceId) && $sourceId > 0
			&& is_int($attempts) && is_int($viewAttempts) && $viewAttempts >= 0 && $viewAttempts < $attempts;
	}

	private function renderOpenFallbackField(string $name, mixed $value, array $fields): string
	{
		if (!$this->hasOpenFallback($fields))
		{
			return 'null';
		}

		return match ($name)
		{
			'fallback_reason' => $value,
			'fallback_view_attempts' => (string)$value,
			'fallback_cleanup_ok' => is_bool($value) ? ($value ? 'true' : 'false') : 'null',
			default => is_int($value) && $value > 0 ? (string)$value : 'null',
		};
	}

	private function renderDiagnosticFacet(string $name, mixed $value, array $fields): ?string
	{
		if (isset(self::DIAGNOSTIC_ENUMS[$name]))
		{
			return is_string($value) && in_array($value, self::DIAGNOSTIC_ENUMS[$name], true)
				? $value
				: (in_array($name, ['missing_object', 'result_kind'], true) ? 'unknown' : 'null');
		}
		if (in_array($name, [
			'delivery_id', 'source_object_id', 'result_object_id', 'result_version_id', 'snapshot_seq',
		], true))
		{
			return $this->renderDiagnosticInteger($value, 1);
		}
		if (in_array($name, ['scope_version_id', 'scope_external_link_id', 'generation_rev'], true))
		{
			return $this->renderDiagnosticInteger($value, 0);
		}
		if ($name === 'scope_session_type')
		{
			$rendered = $this->renderDiagnosticInteger($value, 0, 2);

			return in_array($rendered, ['0', '2'], true) ? $rendered : 'null';
		}
		if ($name === 'generation_correlation_id')
		{
			return is_string($value) && preg_match('/^vog1_[a-f0-9]{64}$/D', $value) === 1
				? $this->quote($value) : 'null';
		}
		if ($name === 'owner_candidates')
		{
			return $this->renderOwnerCandidates($value);
		}
		if ($name === 'owner_candidates_truncated'
			&& is_array($fields['owner_candidates'] ?? null)
			&& count($fields['owner_candidates']) > self::MAX_OWNER_CANDIDATES)
		{
			return 'true';
		}
		if (in_array($name, [
			'source_found', 'result_found', 'source_id_valid', 'delivery_notified',
			'scope_generation_present', 'owner_candidates_truncated',
		], true))
		{
			return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
		}
		if ($name === 'candidates_total_examined')
		{
			return $this->renderDiagnosticInteger($value, 0, self::MAX_CANDIDATES_EXAMINED);
		}

		return null;
	}

	private function renderDiagnosticInteger(mixed $value, int $minimum, int $maximum = PHP_INT_MAX): string
	{
		if (is_string($value))
		{
			$limit = (string)$maximum;
			if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
				|| strlen($value) > strlen($limit)
				|| (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0))
			{
				return 'null';
			}
			$value = (int)$value;
		}

		return is_int($value) && $value >= $minimum && $value <= $maximum ? (string)$value : 'null';
	}

	private function renderOwnerCandidates(mixed $candidates): string
	{
		if (!is_array($candidates) || !array_is_list($candidates))
		{
			return 'null';
		}
		$safe = [];
		foreach (array_slice($candidates, 0, self::MAX_OWNER_CANDIDATES) as $candidate)
		{
			if (!is_array($candidate)
				|| count($candidate) !== count(self::OWNER_CANDIDATE_FIELDS)
				|| array_diff(array_keys($candidate), self::OWNER_CANDIDATE_FIELDS)
				|| (!is_int($candidate['candidate_id']) && $candidate['candidate_id'] !== null)
				|| ($candidate['session_id'] !== null
					&& (!is_int($candidate['session_id']) || $candidate['session_id'] <= 0)))
			{
				return 'null';
			}
			foreach (self::OWNER_CANDIDATE_ENUMS as $name => $allowed)
			{
				if (!in_array($candidate[$name], $allowed, true))
				{
					return 'null';
				}
			}
			$row = [];
			foreach (self::OWNER_CANDIDATE_FIELDS as $name)
			{
				$row[$name] = $candidate[$name];
			}
			$safe[] = $row;
		}

		return json_encode($safe) ?: 'null';
	}

	private function formatRuntimeMetadata(): array
	{
		try
		{
			$config = Configuration::getInstance('disk')->get('vibeoffice_diagnostics');
			$sha = is_array($config) ? ($config['php_build_sha'] ?? null) : null;
			if (is_string($sha) && preg_match('/^(?:[a-fA-F0-9]{40}|[a-fA-F0-9]{64})$/D', $sha) === 1)
			{
				return [
					'php_build_sha=' . $this->quote(strtolower($sha)),
					'php_build_sha_source=deployment', 'db_read_role=unknown',
				];
			}
		}
		catch (\Throwable)
		{
			// Deployment metadata is optional and must not suppress a diagnostic record.
		}

		return ['php_build_sha=unknown', 'php_build_sha_source=unavailable', 'db_read_role=unknown'];
	}

	private function renderEditorField(string $name, mixed $value): string
	{
		if ($value === null)
		{
			return 'null';
		}
		if (in_array($name, ['unsaved_changes', 'disconnected', 'offline', 'refresh_retry_after_present'], true))
		{
			return is_bool($value) ? ($value ? 'true' : 'false') : 'null';
		}
		if (in_array($name, ['previous_editor_key_hash', 'editor_key_hash'], true))
		{
			return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1
				? $this->quote($value) : 'null';
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
		if ($name === 'httpStatus' || $name === 'refresh_last_http_status')
		{
			return is_int($value) && $value >= 100 && $value <= 599 ? (string)$value : 'null';
		}
		if (in_array($name, ['report_attempt_id', 'load_attempt_id', 'platform_doc_id', 'platform_session_id'], true))
		{
			return is_string($value)
				&& preg_match('/\A[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/D', $value) === 1
					? $this->quote(strtolower($value)) : 'null';
		}
		$pattern = match ($name)
		{
			'refresh_request_trace_id' => '/\A(?!0{32}\z)[a-f0-9]{32}\z/D',
			'refresh_request_span_id' => '/\A(?!0{16}\z)[a-f0-9]{16}\z/D',
			'helper_version' => '/\A[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}\+[a-f0-9]{64}\z/D',
			default => null,
		};

		if ($pattern !== null)
		{
			return is_string($value) && preg_match($pattern, $value) === 1 ? $this->quote($value) : 'null';
		}

		$allowed = match ($name)
		{
			'refresh_response_code' => ['not_active', 'saving_in_progress', 'save_refused', 'no_capacity'],
			'attempt_id_source' => ['platform', 'portal'],
			'diagnostic_completeness' => ['full', 'partial'],
			'failure_source' => ['create', 'runtime', 'recovery'],
			'outcome' => ['failed', 'recovered', 'data-loss', 'data-loss-risk', 'observed'],
			'risk_reason' => ['unknown', 'connection-loss', 'unconfirmed-changes'],
			'recovery_trigger' => [
				'engine-disconnect', 'session-refresh', 'hello-mismatch', 'ds-request', 'network-restored',
			],
			'reopen_reason' => ['reopen', 'failover', 'rebalance'],
			'code' => self::EDITOR_CODES,
			'stage' => [
				'api-create', 'ready', 'document-ready', 'refresh', 'validate-config', 'apply-config',
				'runtime', 'recovery', 'unknown',
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

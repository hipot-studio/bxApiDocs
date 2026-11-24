<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class FirstLoadDiagnosticReport
{
	private const FIELDS = [
		'version', 'trigger', 'initialFailure', 'lastFailure', 'appReadyReached', 'documentReadyReached',
		'apiJsFailure', 'apiJsDurationMs', 'sseStatus', 'sseReason', 'online', 'visibility',
	];
	private const CODES = [
		'api-js-load-failed', 'editor-create-failed', 'invalid-open-config', 'refresh-fetch-failed',
		'session-dead', 'recovery-failed', 'ds-error', 'save-refused', 'no-capacity',
		'reopened-unsaved-lost', 'reopened-unsaved-risk', 'app-ready-missing', 'timeout', 'unknown-error',
	];

	private function __construct(private readonly array $data)
	{
	}

	public static function fromArray(array $data): self
	{
		if (
			!self::hasExactKeys($data, self::FIELDS)
			|| $data['version'] !== 1
			|| !in_array($data['trigger'], ['first_failure', 'no_signal', 'terminal', 'recovered'], true)
			|| !self::validFailure($data['initialFailure']) || !self::validFailure($data['lastFailure'])
			|| !is_bool($data['appReadyReached']) || !is_bool($data['documentReadyReached'])
			|| !self::nullableEnum($data['apiJsFailure'], [
				'load_error', 'timeout', 'api_missing', 'append_failed', 'canceled', 'unknown',
			])
			|| !self::nullableInteger($data['apiJsDurationMs'], 0, 2147483647)
			|| !self::nullableEnum($data['sseStatus'], ['connecting', 'retrying', 'checking', 'connected', 'stopped', 'closed'])
			|| !self::nullableEnum($data['sseReason'], [
				'transport-failure', 'connect-timeout', 'probe-unconfirmed', 'budget-exhausted', 'attempts-exhausted',
				'endpoint-rejected', 'first-load-recovery', 'session-closed', 'destroyed', 'cancelled',
			])
			|| ($data['online'] !== null && !is_bool($data['online']))
			|| !in_array($data['visibility'], ['visible', 'hidden', 'unknown'], true)
		)
		{
			throw new \InvalidArgumentException('Invalid first load diagnostic.');
		}

		return new self($data);
	}

	public function toArray(): array
	{
		return $this->data;
	}

	private static function validFailure(mixed $value): bool
	{
		return $value === null || (is_array($value)
			&& self::hasExactKeys($value, ['code', 'stage', 'engineCode', 'httpStatus'])
			&& in_array($value['code'], self::CODES, true)
			&& in_array($value['stage'], [
				'api-create', 'ready', 'document-ready', 'refresh', 'validate-config', 'apply-config', 'unknown',
			], true)
			&& self::nullableInteger($value['engineCode'], -2147483648, 2147483647)
			&& self::nullableInteger($value['httpStatus'], 100, 599));
	}

	private static function hasExactKeys(array $data, array $expected): bool
	{
		$keys = array_keys($data);
		sort($keys);
		sort($expected);

		return $keys === $expected;
	}

	private static function nullableEnum(mixed $value, array $allowed): bool
	{
		return $value === null || in_array($value, $allowed, true);
	}

	private static function nullableInteger(mixed $value, int $min, int $max): bool
	{
		return $value === null || (is_int($value) && $value >= $min && $value <= $max);
	}
}

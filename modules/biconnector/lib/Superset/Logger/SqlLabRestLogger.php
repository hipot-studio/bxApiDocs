<?php

namespace Bitrix\BIConnector\Superset\Logger;

use Bitrix\Main\Web\Json;

class SqlLabRestLogger
{
	private const PLACEMENTS = ['cloud', 'self-hosted'];
	private const OUTCOMES = ['success', 'invalid', 'denied', 'rate_limited', 'timeout', 'error'];
	private const LIMITER_DIMENSIONS = ['portal', 'user', 'caller'];
	private const CODES = [
		'SQLLAB_OK',
		'SQLLAB_INVALID_QUERY',
		'SQLLAB_ACCESS_DENIED',
		'SQLLAB_RATE_LIMIT_EXCEEDED',
		'SQLLAB_TIMEOUT_ERROR',
		'SQLLAB_UNAVAILABLE',
	];
	private const SOURCES = ['server', 'transport'];

	public function log(array $event): void
	{
		$safeEvent = [
			'placement' => $this->allowedString($event['placement'] ?? null, self::PLACEMENTS),
			'durationMs' => max(0, (int)($event['durationMs'] ?? 0)),
			'outcome' => $this->allowedString($event['outcome'] ?? null, self::OUTCOMES),
			'rowCount' => max(0, (int)($event['rowCount'] ?? 0)),
			'limit' => max(0, (int)($event['limit'] ?? 0)),
			'limiterDimension' => $this->allowedNullableString(
				$event['limiterDimension'] ?? null,
				self::LIMITER_DIMENSIONS,
			),
			'code' => $this->allowedString($event['code'] ?? null, self::CODES),
			'source' => $this->allowedNullableString($event['source'] ?? null, self::SOURCES),
			'correlationId' => $this->correlationId($event['correlationId'] ?? null),
			'userHash' => isset($event['userId']) ? hash('sha256', 'user:' . (int)$event['userId']) : null,
			'callerHash' => isset($event['callerKey'])
				? hash('sha256', 'caller:' . (string)$event['callerKey'])
				: null,
		];

		try
		{
			$this->write($safeEvent);
		}
		catch (\Throwable)
		{
		}
	}

	protected function write(array $event): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_INFO,
			'AUDIT_TYPE_ID' => 'SUPERSET_SQL_LAB_REST',
			'MODULE_ID' => 'biconnector',
			'ITEM_ID' => 'superset',
			'DESCRIPTION' => Json::encode($event),
		]);
	}

	private function allowedString(mixed $value, array $allowed): string
	{
		return is_string($value) && in_array($value, $allowed, true) ? $value : 'unknown';
	}

	private function allowedNullableString(mixed $value, array $allowed): ?string
	{
		return is_string($value) && in_array($value, $allowed, true) ? $value : null;
	}

	private function correlationId(mixed $value): ?string
	{
		if (!is_string($value) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $value) !== 1)
		{
			return null;
		}

		return $value;
	}
}

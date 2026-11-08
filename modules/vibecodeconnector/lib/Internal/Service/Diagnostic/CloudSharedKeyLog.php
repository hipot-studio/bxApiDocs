<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Diagnostic;

final class CloudSharedKeyLog
{
	public const AUDIT_TYPE_ID = 'VIBECODECONNECTOR_CLOUD_SHARED_KEY';

	public const REASON_EMPTY = 'empty';
	public const REASON_SIGNATURE = 'signature';
	public const REASON_MANUAL = 'manual';

	public function recordSuccess(string $reason, bool $changed): void
	{
		$this->write(\CEventLog::SEVERITY_INFO, 'OK', [
			'reason' => $reason,
			'changed' => $changed,
		]);
	}

	public function recordFailure(string $reason, \Throwable $error): void
	{
		$this->write(\CEventLog::SEVERITY_SECURITY, 'FAIL', [
			'reason' => $reason,
			'error' => $error->getMessage(),
		]);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function write(string $severity, string $outcome, array $payload): void
	{
		\CEventLog::Log(
			$severity,
			self::AUDIT_TYPE_ID,
			'vibecodeconnector',
			$outcome,
			json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		);
	}
}

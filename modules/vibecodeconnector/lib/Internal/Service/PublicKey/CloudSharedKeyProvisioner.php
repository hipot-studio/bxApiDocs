<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\CloudSharedKeyLog;

/**
 * Single place where the cloud-shared public key is obtained and the attempt is logged.
 *
 * provision() serves the request path: it respects the throttle window and returns an
 * empty string when the attempt was throttled or failed. provisionManually() serves the
 * admin button: the window does not apply and the failure reaches the caller.
 * A failed attempt keeps the previously stored key intact.
 */
final class CloudSharedKeyProvisioner
{
	public function __construct(
		private readonly CloudSharedKeyProvider $keyProvider = new CloudSharedKeyRefresher(),
		private readonly KeyRefreshThrottle $throttle = new KeyRefreshThrottle(),
		private readonly CloudSharedKeyLog $log = new CloudSharedKeyLog(),
	) {
	}

	/**
	 * @param string $reason why the key is needed: CloudSharedKeyLog::REASON_*
	 */
	public function provision(string $reason, string $previousKey = ''): string
	{
		if (!$this->throttle->isAllowed())
		{
			return '';
		}

		$this->throttle->markAttempt();

		try
		{
			$pem = $this->keyProvider->refresh();
		}
		catch (\Throwable $e)
		{
			$this->record(fn () => $this->log->recordFailure($reason, $e));

			return '';
		}

		$this->record(fn () => $this->log->recordSuccess($reason, $pem !== $previousKey));

		return $pem;
	}

	/**
	 * Admin-initiated fetch: the throttle window does not apply, and the failure is
	 * rethrown so the admin page can show why the key did not arrive.
	 *
	 * @throws \Throwable
	 */
	public function provisionManually(string $previousKey = ''): string
	{
		try
		{
			$pem = $this->keyProvider->refresh();
		}
		catch (\Throwable $e)
		{
			$this->record(fn () => $this->log->recordFailure(CloudSharedKeyLog::REASON_MANUAL, $e));

			throw $e;
		}

		$this->record(fn () => $this->log->recordSuccess(CloudSharedKeyLog::REASON_MANUAL, $pem !== $previousKey));

		return $pem;
	}

	private function record(callable $write): void
	{
		try
		{
			$write();
		}
		catch (\Throwable)
		{
			// Diagnostics must never break request handling.
		}
	}
}

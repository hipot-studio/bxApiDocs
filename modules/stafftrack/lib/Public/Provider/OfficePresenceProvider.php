<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Internal\Service\Office\PresenceService;

/**
 * The published reading of the presence domain: whether an employee may confirm their presence by a
 * signal of the client. The rule is the one of {@see PresenceService} and is not repeated here; the two
 * other entrances of the service - the write of the points and the read of the statuses - are not
 * published, so the provider stands beside the service rather than under it.
 *
 * A failure on this path is read as "no" and journalled: the answer is one of the conditions the client
 * starts its whole cycle by, and a fault of the presence must not take the rest of them down with it.
 * The service itself swallows nothing - the boundary does.
 */
class OfficePresenceProvider
{
	private PresenceService $presenceService;

	public function __construct(?PresenceService $presenceService = null)
	{
		$this->presenceService = $presenceService ?? new PresenceService();
	}

	/**
	 * An identifier that names no employee is dropped before the service is asked: the facade is public
	 * and does not inherit the authentication prefilter of the controller.
	 */
	public function isSignalAllowed(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			return $this->presenceService->isSignalAllowed($userId);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return false;
		}
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message and never its trace: a
	 * storage error carries the query it failed on, and the frames of a trace carry their arguments.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}

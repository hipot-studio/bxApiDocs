<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Invitation;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Invitation;
use Bitrix\Main\Application;

class InvitationCounterResyncService
{
	private \Closure $resyncScheduler;
	private \Closure $exceptionWriter;

	public function __construct(
		?\Closure $resyncScheduler = null,
		?\Closure $exceptionWriter = null,
	)
	{
		$this->resyncScheduler = $resyncScheduler
			?? static function (?\Bitrix\Intranet\User $originator): void {
				Invitation::addResyncAllCountersTask($originator);
			};
		$this->exceptionWriter = $exceptionWriter
			?? static function (\Throwable $exception): void {
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			};
	}

	public function captureTarget(User $user): ?InvitationCounterResyncTarget
	{
		try
		{
			$userId = (int)$user->getId();

			if ($userId <= 0)
			{
				return null;
			}

			$invitedUser = new \Bitrix\Intranet\User($userId);

			if ($invitedUser->isInitializedUser())
			{
				return null;
			}

			return new InvitationCounterResyncTarget($invitedUser->fetchOriginatorUser());
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);

			return null;
		}
	}

	public function resync(?InvitationCounterResyncTarget $target): void
	{
		if ($target === null)
		{
			return;
		}

		try
		{
			($this->resyncScheduler)($target->originator);
		}
		catch (\Throwable $exception)
		{
			$this->logThrowable($exception);
		}
	}

	private function logThrowable(\Throwable $exception): void
	{
		($this->exceptionWriter)(new \RuntimeException(
			'Invitation counters resync failed: ' . $exception->getMessage(),
			is_int($exception->getCode()) ? $exception->getCode() : 0,
			$exception,
		));
	}
}

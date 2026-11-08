<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Service;

use Bitrix\Intranet\Contract\SendableContract;
use Bitrix\Intranet\Internal\Service\Invitation\InvitationEmailRateLimiter;

class RateLimitedEmailMessage implements SendableContract
{
	public function __construct(
		private readonly SendableContract $message,
		private readonly string $email,
		private readonly InvitationEmailRateLimiter $rateLimiter,
	)
	{
	}

	/**
	 * @throws \Bitrix\Intranet\Exception\InvitationFailedException
	 */
	public function send(): void
	{
		$token = $this->rateLimiter->tryMarkSent($this->email);

		try
		{
			$this->message->send();
		}
		catch (\Throwable $exception)
		{
			$this->releaseMark($token);

			throw $exception;
		}
	}

	/**
	 * @throws \Bitrix\Intranet\Exception\InvitationFailedException
	 */
	public function sendImmediately(): void
	{
		$this->sendImmediatelyWithResult();
	}

	/**
	 * @throws \Bitrix\Intranet\Exception\InvitationFailedException
	 */
	public function sendImmediatelyWithResult(): bool
	{
		$token = $this->rateLimiter->tryMarkSent($this->email);

		try
		{
			$isSent = $this->message->sendImmediatelyWithResult();
		}
		catch (\Throwable $exception)
		{
			$this->releaseMark($token);

			throw $exception;
		}

		if (!$isSent)
		{
			$this->releaseMark($token);
		}

		return $isSent;
	}

	private function releaseMark(?string $token): void
	{
		if ($token !== null)
		{
			$this->rateLimiter->release($this->email, $token);
		}
	}
}

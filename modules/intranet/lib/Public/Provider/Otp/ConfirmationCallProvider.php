<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Service\Otp\ConfirmationCallService;
use Bitrix\Main\Result;

class ConfirmationCallProvider
{
	public function __construct(private readonly ConfirmationCallService $service = new ConfirmationCallService())
	{
	}

	/** Returns the status of the call bound to the user's setup phone. */
	public function getSetupStatus(User $user, string $confirmationCallId): Result
	{
		return $this->service->getSetupStatus($user, $confirmationCallId);
	}

	/** Returns the status of the call bound to the deferred login. */
	public function getLoginStatus(string $confirmationCallId): Result
	{
		return $this->service->getLoginStatus($confirmationCallId);
	}

	/** Returns whether the setup call channel is available. */
	public function isSetupAvailable(int $userId): bool
	{
		return $this->service->isSetupAvailable($userId);
	}
}

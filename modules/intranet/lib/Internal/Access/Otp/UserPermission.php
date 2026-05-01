<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Access\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;

class UserPermission
{
	public function __construct(
		private readonly User $user,
	) {
	}

	public function canEdit(): bool
	{
		return $this->user->isCurrent();
	}

	public function canView(): bool
	{
		return $this->user->isCurrent() || $this->canCurrentUserEdit();
	}

	public function canActivate(): bool
	{
		return $this->user->isCurrent() || $this->canCurrentUserEdit();
	}

	public function canDeactivate(): bool
	{
		if ($this->isCurrentIntegrator())
		{
			return false;
		}

		$otpSettings = new OtpSettings();
		$isMandatoryForUser = false;

		if ($otpSettings->isMandatoryUsing())
		{
			$personalSettings = $otpSettings->getPersonalSettingsByUserId($this->user->getId());
			$isMandatoryForUser = $personalSettings?->isRequired() ?? false;
		}

		return (!$isMandatoryForUser && $this->user->isCurrent()) || $this->canCurrentUserEdit();
	}

	public function canCurrentUserEdit(): bool
	{
		global $USER;

		return $USER->CanDoOperation('security_edit_user_otp');
	}

	private function isCurrentIntegrator(): bool
	{
		return $this->user->isCurrent() && $this->user->isIntegrator();
	}
}

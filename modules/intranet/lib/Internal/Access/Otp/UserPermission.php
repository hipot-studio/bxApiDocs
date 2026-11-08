<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Access\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;

class UserPermission
{
	public function __construct(
		private readonly User $user,
		private ?OtpSettings $otpSettings = null,
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

		return $this->isSelfWithoutMandatoryOtp() || $this->canCurrentUserEdit();
	}

	public function canDefer(): bool
	{
		return $this->canCurrentUserEdit();
	}

	public function canSetIndefinitePeriod(): bool
	{
		if ($this->user->isIntegrator())
		{
			return false;
		}

		return $this->canCurrentUserEdit();
	}

	public function canCurrentUserEdit(): bool
	{
		return self::canCurrentUserEditOtp();
	}

	public static function canCurrentUserEditOtp(): bool
	{
		global $USER;

		return $USER->CanDoOperation('security_edit_user_otp');
	}

	private function isSelfWithoutMandatoryOtp(): bool
	{
		if (!$this->user->isCurrent())
		{
			return false;
		}

		$otpSettings = $this->getOtpSettings();

		if (!$otpSettings->isMandatoryUsing())
		{
			return true;
		}

		$personalSettings = $otpSettings->getPersonalSettingsByUserId($this->user->getId());

		return !($personalSettings?->isRequired() ?? false);
	}

	private function isCurrentIntegrator(): bool
	{
		return $this->user->isCurrent() && $this->user->isIntegrator();
	}

	private function getOtpSettings(): OtpSettings
	{
		return $this->otpSettings ??= new OtpSettings();
	}
}

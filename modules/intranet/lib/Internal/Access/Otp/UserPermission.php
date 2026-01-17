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
		return $this->user->isCurrent() || $this->canCurrentUserEdit();
	}

	public function canDeactivate(): bool
	{
		$ifMandatory = !(new OtpSettings())->isMandatoryUsing() && $this->user->isCurrent();

		return $ifMandatory || $this->canCurrentUserEdit();
	}

	public function canCurrentUserEdit(): bool
	{
		global $USER;

		return $USER->CanDoOperation('security_edit_user_otp');
	}
}

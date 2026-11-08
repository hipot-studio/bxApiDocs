<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

interface MobileQualificationPresetAccessChecker
{
	public function isAdmin(): bool;

	public function isExternalUser(): bool;

	public function isPortalEligible(): bool;
}

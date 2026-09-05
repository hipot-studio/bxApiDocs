<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Profile\View\Rule;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Profile\View\ProfileView;
use Bitrix\Intranet\Profile\View\ProfileViewRule;

final class SystemUserProfileRule implements ProfileViewRule
{
	public function matches(User $user): bool
	{
		return $user->isSystemUser();
	}

	public function createView(): ProfileView
	{
		return ProfileView::systemUser();
	}
}

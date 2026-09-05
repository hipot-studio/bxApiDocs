<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Profile\View;

use Bitrix\Intranet\Entity\User;

interface ProfileViewRule
{
	public function matches(User $user): bool;

	public function createView(): ProfileView;
}

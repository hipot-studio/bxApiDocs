<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

class FirstAuthorizeInitializationPolicy
{
	public function shouldInitialize(
		bool $isCloud,
		bool $isVirtualSession,
		bool $isUpdate,
		bool $hasLastLogin,
		mixed $lastLogin,
	): bool
	{
		return !$isCloud
			&& !$isVirtualSession
			&& $isUpdate
			&& $hasLastLogin
			&& trim((string)$lastLogin) === ''
		;
	}
}

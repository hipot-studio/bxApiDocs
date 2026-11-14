<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

final class LegacyAppAccessChecker
{
	private readonly bool $isAdmin;
	private readonly array $userCodes;

	public function __construct(int $userId)
	{
		global $USER;

		$isCurrentUser = $USER instanceof \CUser && (int)$USER->GetID() === $userId;
		$this->isAdmin = $isCurrentUser
			? \CRestUtil::isAdmin()
			: ($userId > 0 && \CRestUtil::isAdmin($userId))
		;
		$this->userCodes = $isCurrentUser
			? $USER->GetAccessCodes()
			: ($userId > 0 ? array_merge(\CAccess::GetUserCodesArray($userId), ['AU']) : ['G2'])
		;
	}

	public function canAccessMenu(array $accessCodes): bool
	{
		return $this->isAdmin || empty($accessCodes) || !empty(array_intersect($accessCodes, $this->userCodes));
	}

	public static function canCurrentUserAccessCodes(array $accessCodes): bool
	{
		global $USER;

		return empty($accessCodes)
			|| ($USER instanceof \CUser && $USER->CanAccess($accessCodes))
			|| \CRestUtil::isAdmin()
		;
	}
}

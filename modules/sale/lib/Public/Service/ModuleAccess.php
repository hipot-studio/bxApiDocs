<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Service;

/**
 * Single place holding the module right letters and access rules of sale for the current user.
 *
 * Rights are compared as letters: reading needs a right strictly above denied,
 * modifying needs write or above (an administrator always gets write).
 */
final class ModuleAccess
{
	private const RIGHT_DENIED = 'D';
	private const RIGHT_WRITE = 'W';

	public function canRead(): bool
	{
		return $this->isReadRight($this->getCurrentUserRight());
	}

	public function canModify(): bool
	{
		return $this->isModifyRight($this->getCurrentUserRight());
	}

	public function isReadRight(string $right): bool
	{
		return $right > self::RIGHT_DENIED;
	}

	public function isModifyRight(string $right): bool
	{
		return $right >= self::RIGHT_WRITE;
	}

	private function getCurrentUserRight(): string
	{
		global $APPLICATION;

		return (string)$APPLICATION->GetGroupRight('sale');
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use CAccess;

/**
 * Wrapper over the main access API (`\CAccess`).
 * Returns the user's access codes (U<id>, G<id>, D<id>, AU, ...).
 */
class AccessCodes
{
	private const ACCESS_CODE_AUTHORIZED = 'AU';

	/**
	 * @return string[]
	 */
	public function getUserCodes(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		// b_user_access may hold duplicates until CAccess::deleteDuplicatesAgent() has run.
		$codes = array_values(array_unique($this->fetchStoredCodes($userId)));
		if ($codes === [])
		{
			// No stored codes means no access at all, so no `AU` either.
			return [];
		}

		// `AU` has no row in b_user_access, see \CUser::GetAccessCodes()
		$codes[] = self::ACCESS_CODE_AUTHORIZED;

		return $codes;
	}

	/**
	 * Codes materialized in b_user_access. Seam for tests.
	 *
	 * @return string[]
	 */
	protected function fetchStoredCodes(int $userId): array
	{
		return CAccess::getUserCodesArray($userId);
	}
}

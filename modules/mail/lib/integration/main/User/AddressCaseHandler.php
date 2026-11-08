<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Main\User;

use Bitrix\Mail\Internal\Service\User\MixedCaseAddressPresence;
use Bitrix\Main\UserTable;

/**
 * Watches the saving of a portal user for the one thing the sender photo depends on: an address stored
 * in a case the comparison by value cannot match. The first address of that kind on a portal turns the
 * answer of {@see MixedCaseAddressPresence} over, and until the answer is dropped the reading of a photo
 * skips the folded condition and does not see the employee who holds it.
 *
 * The answer is dropped only when the saved address really differs from its own fold. Dropping it on
 * every save would put the portal back to paying a scan of b_user for the question again and again,
 * which is the cost the answer exists to remove.
 */
final class AddressCaseHandler
{
	public static function onUserSaved(array $fields): void
	{
		try
		{
			if (empty($fields['RESULT']))
			{
				return;
			}

			$address = self::savedAddress($fields);
			if ($address === null || $address === mb_strtolower($address))
			{
				return;
			}

			MixedCaseAddressPresence::forget();
		}
		catch (\Throwable)
		{
			// the answer is a cache of ours, and failing to drop it must not fail the saving of the user
		}
	}

	/**
	 * The address the save makes current for an active user, or null when the save cannot bring one
	 * into play. A save that carries the address answers by itself. A save that only turns a user
	 * active leaves his address where it was, and the address still has to be read, because until now
	 * it belonged to nobody the lookup would have counted.
	 */
	private static function savedAddress(array $fields): ?string
	{
		if (isset($fields['ACTIVE']) && (string)$fields['ACTIVE'] !== 'Y')
		{
			return null;
		}
		if (isset($fields['EMAIL']))
		{
			return trim((string)$fields['EMAIL']);
		}
		if (!isset($fields['ACTIVE']))
		{
			return null;
		}

		$userId = (int)($fields['ID'] ?? 0);
		if ($userId <= 0)
		{
			return null;
		}
		$user = UserTable::query()
			->setSelect(['EMAIL'])
			->where('ID', $userId)
			->fetch()
		;

		return $user === false ? null : trim((string)$user['EMAIL']);
	}
}

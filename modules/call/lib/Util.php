<?php

namespace Bitrix\Call;

use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class Util
{
	/** @var array<int, array> */
	private static array $userDataCache = [];

	/**
	 * @param int[] $userIds
	 */
	public static function getUsers(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$userIds = array_unique(array_map('intval', $userIds));

		$result = [];
		$missing = [];
		foreach ($userIds as $userId)
		{
			if (isset(self::$userDataCache[$userId]))
			{
				$result[$userId] = self::$userDataCache[$userId];
			}
			else
			{
				$missing[] = $userId;
			}
		}

		if (empty($missing))
		{
			return $result;
		}

		Loader::includeModule('im');

		$collection = new UserCollection($missing);
		foreach ($collection as $user)
		{
			$userData = $user->getArray(['JSON' => 'Y', 'WITHOUT_ONLINE' => true]);
			if (!$userData || empty($userData['id']))
			{
				continue;
			}

			$userId = (int)$userData['id'];
			$entry = [
				'id' => $userData['id'],
				'first_name' => $userData['first_name'] ?? null,
				'last_name' => $userData['last_name'] ?? null,
				'name' => $userData['name'] ?? null,
				'work_position' => $userData['work_position'] ?? null,
				'extranet' => $userData['extranet'] ?? null,
				'invited' => $userData['invited'] ?? null,
				'last_activity_date' => $userData['last_activity_date'] ?? false,
				'avatar' => $userData['avatar'] ?? null,
				'avatar_hr' => $userData['avatar_hr'] ?? null,
				'gender' => $userData['gender'] ?? null,
				'color' => $userData['color'] ?? null,
				'type' => $userData['type'] ?? null,
			];

			self::$userDataCache[$userId] = $entry;
			$result[$userId] = $entry;
		}

		return $result;
	}

	public static function clearUsersCache(?array $userIds = null): void
	{
		if ($userIds === null)
		{
			self::$userDataCache = [];
			return;
		}

		foreach ($userIds as $userId)
		{
			unset(self::$userDataCache[(int)$userId]);
		}
	}

	public static function generateUUID(): string
	{
		if (function_exists('random_bytes'))
		{
			$data = random_bytes(16);
		}
		elseif (function_exists('openssl_random_pseudo_bytes'))
		{
			$data = openssl_random_pseudo_bytes(16);
		}
		else
		{
			$data = uniqid('', true);
		}

		// set version to 4
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);

		// set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
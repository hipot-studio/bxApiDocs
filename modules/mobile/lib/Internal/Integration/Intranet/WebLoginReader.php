<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Intranet;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\Main\UserOptionReader;

/**
 * The moment of the last login from the web or the desktop, the mark intranet leaves in an option of the
 * user. The pack is read from the table of options, in the portions of UserOptionReader: the reader of
 * intranet answers one user at a time. Null means the intranet module is missing; a read that fails
 * throws what it met, since a failure is not an unknown.
 */
class WebLoginReader
{
	private const OPTION_CATEGORY = 'intranet';
	private const OPTION_NAME = 'lastWebAuthorizeTime';

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime>|null userId => the last login, absent for a user who never logged in
	 */
	public function getLastLogin(array $userIds): ?array
	{
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		$logins = [];
		foreach ($this->readLoginOptions(self::normalize($userIds)) as $userId => $value)
		{
			$timestamp = (int)unserialize($value, ['allowed_classes' => false]);
			if ($timestamp > 0)
			{
				$logins[$userId] = DateTime::createFromTimestamp($timestamp);
			}
		}

		return $logins;
	}

	protected function isModuleAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string> userId => the stored mark, serialized
	 */
	protected function readLoginOptions(array $userIds): array
	{
		return UserOptionReader::read($userIds, self::OPTION_CATEGORY, self::OPTION_NAME);
	}

	/**
	 * @param array<int|string> $userIds
	 * @return int[]
	 */
	private static function normalize(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}

<?php

namespace Bitrix\Mobile\Internal\Onboarding\Service;

use Bitrix\Main\Type\DateTime;

class MobileActivityService
{
	private const OPTION_CATEGORY = 'mobile';
	private const IOS_ACTIVITY_OPTION = 'iOsLastActivityDate';
	private const ANDROID_ACTIVITY_OPTION = 'AndroidLastActivityDate';

	public function getActivityDate(int $userId): ?DateTime
	{
		$iosTimestamp = $this->getActivityTimestamp($userId, self::IOS_ACTIVITY_OPTION);
		$androidTimestamp = $this->getActivityTimestamp($userId, self::ANDROID_ACTIVITY_OPTION);

		$activityTimestamp = $this->getEarliestTimestamp($iosTimestamp, $androidTimestamp);

		if ($activityTimestamp === null)
		{
			return null;
		}

		return DateTime::createFromTimestamp($activityTimestamp);
	}

	/**
	 * @return array<int, int>
	 */
	public function getActivityTimestampsByUser(): array
	{
		$timestamps = [];
		foreach ([self::IOS_ACTIVITY_OPTION, self::ANDROID_ACTIVITY_OPTION] as $optionName)
		{
			$result = \CUserOptions::GetList([], [
				'CATEGORY' => mb_strtolower(self::OPTION_CATEGORY),
				'NAME' => mb_strtolower($optionName),
			]);

			while ($row = $result->Fetch())
			{
				$userId = (int)$row['USER_ID'];
				if ($userId <= 0)
				{
					continue;
				}

				$timestamp = (int)unserialize($row['VALUE'], ['allowed_classes' => false]);
				if ($timestamp <= 0)
				{
					continue;
				}

				$timestamps[$userId] = isset($timestamps[$userId])
					? min($timestamps[$userId], $timestamp)
					: $timestamp;
			}
		}

		return $timestamps;
	}

	/**
	 * @return int[]
	 */
	public function getUserIdsWithActivity(): array
	{
		return array_keys($this->getActivityTimestampsByUser());
	}

	public function hasMobileActivity(int $userId): bool
	{
		$iosTimestamp = $this->getActivityTimestamp($userId, self::IOS_ACTIVITY_OPTION);
		$androidTimestamp = $this->getActivityTimestamp($userId, self::ANDROID_ACTIVITY_OPTION);

		return $iosTimestamp !== null || $androidTimestamp !== null;
	}

	public function getDaysSinceActivity(int $userId): ?int
	{
		$activityDate = $this->getActivityDate($userId);
		if ($activityDate === null)
		{
			return null;
		}

		$now = new DateTime();
		$diff = $now->getTimestamp() - $activityDate->getTimestamp();

		return (int)floor($diff / (60 * 60 * 24));
	}

	public function clearActivity(int $userId): bool
	{
		$deletedIos = \CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::IOS_ACTIVITY_OPTION, false, $userId);
		$deletedAndroid = \CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::ANDROID_ACTIVITY_OPTION, false, $userId);

		return $deletedIos || $deletedAndroid;
	}

	public function clearAllUsersActivity(): bool
	{
		$deletedIos = \CUserOptions::DeleteOptionsByName(self::OPTION_CATEGORY, self::IOS_ACTIVITY_OPTION);
		$deletedAndroid = \CUserOptions::DeleteOptionsByName(self::OPTION_CATEGORY, self::ANDROID_ACTIVITY_OPTION);

		return $deletedIos || $deletedAndroid;
	}

	private function getActivityTimestamp(int $userId, string $optionName): ?int
	{
		$value = \CUserOptions::GetOption(self::OPTION_CATEGORY, $optionName, null, $userId);

		if (empty($value))
		{
			return null;
		}

		$timestamp = (int)$value;

		return $timestamp > 0 ? $timestamp : null;
	}

	private function getEarliestTimestamp(?int $timestamp1, ?int $timestamp2): ?int
	{
		if ($timestamp1 === null)
		{
			return $timestamp2;
		}

		if ($timestamp2 === null)
		{
			return $timestamp1;
		}

		return min($timestamp1, $timestamp2);
	}

}

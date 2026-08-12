<?php

namespace Bitrix\Im\Configuration;

use Bitrix\Im\V2\Pull\Event\SettingsUpdate;
use Bitrix\Im\V2\Settings\CacheManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use COption;

class Manager
{
	private const GENERAL = 'general';
	private const NOTIFY = 'notify';

	private const PRIVACY_SEARCH = 'privacySearch';
	private const STATUS = 'status';

	/** General settings whose change is broadcast to the user's other devices in realtime. */
	private const SYNCED_GENERAL_SETTINGS = [
		\Bitrix\Im\V2\Settings\Entity\General::OPEN_DESKTOP_FROM_PANEL,
		\Bitrix\Im\V2\Settings\Entity\General::DEFAULT_REACTION,
	];

	public static function getUserSettings(int $userId): Result
	{
		$result = new Result();
		try
		{
			$preset = Configuration::getUserPreset($userId);
		}
		catch (ObjectPropertyException | ArgumentException | SystemException $exception)
		{
			$result->addError(new Error($exception->getMessage(), $exception->getCode()));
			return $result;
		}

		$result->setData($preset);
		return $result;
	}

	/**
	 * @param int $userId
	 * @param array{notify: array, general: array} $settings
	 * @return Result
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function setUserSettings(int $userId, array $settings): Result
	{
		$result = new Result();
		if (
			!array_key_exists(self::NOTIFY, $settings)
			|| !array_key_exists(self::GENERAL, $settings)
		)
		{
			$result->addError(new Error('Incorrect data when receiving chat settings', 400));
			return $result;
		}

		self::updateUserStatus($userId, $settings['general']);

		self::sendPullGeneralSettingsUpdate($userId, $settings['general']);

		self::disableUserSearch($userId, $settings['general']);

		if (isset($settings['general']['notifyScheme']) && $settings['general']['notifyScheme'] === 'simple')
		{
			$settings['notify'] = Notification::getSimpleNotifySettings($settings['general']);
		}

		$userPresetId =
			\Bitrix\Im\Model\OptionGroupTable::query()
				->addSelect('ID')
				->where('USER_ID', $userId)
				->fetch()
		;

		if (!$userPresetId)
		{
			Configuration::createUserPreset($userId, $settings);

			CacheManager::getUserCache($userId)->clearCache();
			self::enableUserSearch($userId, $settings['general']);

			return $result;
		}

		$userPresetId = $userPresetId['ID'];
		Configuration::updatePresetSettings($userPresetId, $userId, $settings);
		Configuration::chooseExistingPreset($userPresetId, $userId);

		CacheManager::getPresetCache($userPresetId)->clearCache();

		self::enableUserSearch($userId, $settings['general']);

		return $result;
	}

	public static function setUserSetting(int $userId, string $type, array $settings): Result
	{
		$result = new Result();
		if (!in_array($type, [self::NOTIFY, self::GENERAL], true))
		{
			$result->addError(new Error('Incorrect data when receiving chat settings', 400));
			return $result;
		}

		$userPresetId =
			\Bitrix\Im\Model\OptionGroupTable::query()
				->addSelect('ID')
				->where('USER_ID', $userId)
				->fetch()
		;
		$userPresetId = $userPresetId['ID'] ?? null;

		if ($type === self::NOTIFY)
		{
			if (!$userPresetId)
			{
				$preset['notify'] = $settings;
				$preset['general'] = [];
				Configuration::createUserPreset($userId, $preset);

				return $result;
			}
			Notification::updateGroupSettings($userPresetId, $settings);
		}

		if ($type === self::GENERAL)
		{
			self::updateUserStatus($userId, $settings);

			self::sendPullGeneralSettingsUpdate($userId, $settings);

			self::disableUserSearch($userId, $settings);

			if (!$userPresetId)
			{
				$preset['general'] = array_replace_recursive(General::getDefaultSettings(), $settings);
				$preset['notify'] = [];
				Configuration::createUserPreset($userId, $preset);

				return $result;
			}
			General::updateGroupSettings($userPresetId, $settings);

			self::enableUserSearch($userId, $settings);
		}

		CacheManager::getPresetCache($userPresetId)->clearCache();
		CacheManager::getUserCache($userId)->clearCache();

		return $result;
	}

	public static function isSettingsMigrated(): bool
	{
		return
			COption::GetOptionString('im', 'migration_to_new_settings') === 'Y'
			|| COption::GetOptionString('im', \Bitrix\Im\Configuration\Configuration::DEFAULT_PRESET_SETTING_NAME, null) !== null
		;
	}

	public static function isUserMigrated(int $userId): bool
	{
		$lastConvertedId = COption::GetOptionInt('im', 'last_converted_user');
		return $userId < $lastConvertedId;
	}

	public static function getNotifyAccess($userId, $moduleId, $eventId, $type)
	{
		$generalSettings = General::createWithUserId($userId);
		$notifyScheme = $generalSettings->getValue('notifyScheme');

		if ($notifyScheme !== 'expert')
		{
			if ($type === Notification::SITE)
			{
				return $generalSettings->getValue('notifySchemeSendSite');
			}
			if ($type === Notification::MAIL)
			{
				return $generalSettings->getValue('notifySchemeSendEmail');
			}
			if ($type === Notification::PUSH)
			{
				return $generalSettings->getValue('notifySchemeSendPush');
			}
			if ($type === Notification::XMPP)
			{
				return $generalSettings->getValue('notifySchemeSendXmpp');
			}
		}

		return (new Notification($moduleId, $eventId))->isAllowed($userId, $type);
	}

	public static function sendPullGeneralSettingsUpdate(int $userId, array $generalSettings): void
	{
		$syncedSettings = array_intersect_key(
			$generalSettings,
			array_flip(self::SYNCED_GENERAL_SETTINGS)
		);
		if ($syncedSettings === [])
		{
			return;
		}

		// Broadcast the persisted values: checkingValues normalizes input exactly as the write path does.
		$verifiedSettings = General::checkingValues($syncedSettings);
		if ($verifiedSettings === [])
		{
			return;
		}

		self::sendPullSettingsUpdate($userId, $verifiedSettings);
	}

	private static function sendPullSettingsUpdate(int $userId, array $settings): void
	{
		(new SettingsUpdate($userId, $settings))->send();
	}

	private static function disableUserSearch(int $userId, array $generalSettings): void
	{
		$defaultSettings = General::getDefaultSettings();
		if (
			array_key_exists(self::PRIVACY_SEARCH, $generalSettings)
			&& $defaultSettings[self::PRIVACY_SEARCH] === $generalSettings[self::PRIVACY_SEARCH]
		)
		{
			global $USER_FIELD_MANAGER;
			$USER_FIELD_MANAGER->Update("USER", $userId, ['UF_IM_SEARCH' => '']);
		}
	}

	private static function enableUserSearch(int $userId, array $generalSettings): void
	{
		if (isset($generalSettings[self::PRIVACY_SEARCH]))
		{
			global $USER_FIELD_MANAGER;
			$USER_FIELD_MANAGER->Update(
				"USER",
				$userId,
				[
					'UF_IM_SEARCH' => $generalSettings[self::PRIVACY_SEARCH],
				]
			);
		}
	}

	private static function updateUserStatus(int $userId, array $generalSettings): void
	{
		if (isset($generalSettings[self::STATUS]))
		{
			\CIMStatus::Set($userId, ['STATUS' => $generalSettings[self::STATUS]]);
		}
	}

}
<?php

namespace Bitrix\BIConnector\Integration\Superset\Events\Main;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Access\Superset\Synchronizer;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;

/**
 * Event handlers for user
 */
class User
{
	private static ?array $currentUserFields = null;

	public static function onBeforeUserUpdate(array $fields): void
	{
		if (isset($fields['ID']) && ((int)$fields['ID']) > 0)
		{
			$userData = UserTable::getById((int)$fields['ID'])->fetch();
			if ($userData)
			{
				self::$currentUserFields = $userData;
			}
		}
	}

	/**
	 * Update superset user
	 *
	 * @param array $fields
	 * @return void
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return;
		}

		$userId = 0;

		if (!self::$currentUserFields)
		{
			return;
		}

		if (isset($fields['ID']) && ((int)$fields['ID']) > 0)
		{
			$userId = (int)$fields['ID'];
		}
		else
		{
			return;
		}

		$user = self::getUser($userId);
		if (!$user || empty($user->clientId))
		{
			return;
		}

		$isChangedActivity = isset($fields['ACTIVE']) && ($fields['ACTIVE'] !== self::$currentUserFields['ACTIVE']);
		if ($isChangedActivity)
		{
			self::changeActivity($user, $fields['ACTIVE'] === 'Y');
		}

		$isChangedEmail = isset($fields['EMAIL']) && $fields['EMAIL'] !== self::$currentUserFields['EMAIL'];
		$isChangedName = isset($fields['NAME']) && $fields['NAME'] !== self::$currentUserFields['NAME'];
		$isChangedLastName = isset($fields['LAST_NAME']) && $fields['LAST_NAME'] !== self::$currentUserFields['LAST_NAME'];
		if ($isChangedName || $isChangedLastName || $isChangedEmail)
		{
			$login = self::$currentUserFields['LOGIN'];
			if (!empty($fields['LOGIN']))
			{
				$login = $fields['LOGIN'];
			}

			$email = ($login . '@bitrix.bi');
			if (!empty($fields['EMAIL']))
			{
				$email = $fields['EMAIL'];
			}

			$name = $login;
			if (!empty($fields['NAME']))
			{
				$name = $fields['NAME'];
			}

			$lastName = $login;
			if (!empty($fields['LAST_NAME']))
			{
				$lastName = $fields['LAST_NAME'];
			}

			self::updateUser(
				$user,
				$email,
				$name,
				$lastName
			);
		}
	}

	private static function changeActivity(Dto\User $user, bool $isActive): void
	{
		$integrator = Integrator::getInstance();

		Application::getInstance()->addBackgroundJob(function() use ($integrator, $user, $isActive) {
			if ($isActive)
			{
				$integrator->activateUser($user);
				(new Synchronizer($user->id))->sync();
			}
			else
			{
				$integrator->deactivateUser($user);
				$integrator->setEmptyRole($user);
			}
		});

		SupersetUserTable::updatePermissionHash($user->id, '');
	}

	private static function updateUser(Dto\User $user, string $email, string $firstName, string $lastName): void
	{
		$user->userName = $email;
		$user->email = $email;
		$user->firstName = $firstName;
		$user->lastName = $lastName;

		$integrator = Integrator::getInstance();

		Application::getInstance()->addBackgroundJob(function() use ($integrator, $user) {
			$integrator->updateUser($user);
		});
	}

	private static function getUser(int $userId): ?Dto\User
	{
		return (new SupersetUserRepository)->getById($userId);
	}
}
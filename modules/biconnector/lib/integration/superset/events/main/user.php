<?php

namespace Bitrix\BIConnector\Integration\Superset\Events\Main;

use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
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
	private static array $currentUserFields;

	private static array $changableFields = [
		'ACTIVE',
		'NAME',
		'LAST_NAME',
	];

	/**
	 * Gets and keeps current user fields before update.
	 *
	 * @param array $fields
	 * @return void
	 */
	public static function onBeforeUserUpdate(array $fields): void
	{
		/**
		 * UF_CONNECTOR_MD5 uses in imconnector for OL user
		 * Skip this user
		 */
		if ($fields['UF_CONNECTOR_MD5'] ?? false)
		{
			return;
		}

		$userId = (int)($fields['ID'] ?? 0);

		if (
			!$userId
			|| empty(array_intersect(self::$changableFields, array_keys($fields)))
			|| !self::isSupersetUser($userId)
		)
		{
			return;
		}

		$currentUser = CurrentUser::get();

		if ($userId === (int)$currentUser->getId())
		{
			self::$currentUserFields[$userId] = [
				'ACTIVE' => 'Y',
				'NAME' => $currentUser->getFirstName(),
				'LAST_NAME' => $currentUser->getLastName(),
				'LOGIN' => $currentUser->getLogin(),
			];
		}
		else
		{
			self::$currentUserFields[$userId] = UserTable::getRow([
				'select' => array_merge(self::$changableFields, ['LOGIN']),
				'filter' => [
					'=ID' => $userId,
				],
			]);
		}
	}

	/**
	 * Checks and updates superset user after user update.
	 * Uses addBackgroundJob for updating user in background.
	 *
	 * @param array $fields
	 * @return void
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		/**
		 * UF_CONNECTOR_MD5 uses in imconnector for OL user
		 * Skip this user
		 */
		if ($fields['UF_CONNECTOR_MD5'] ?? false)
		{
			return;
		}

		$userId = (int)($fields['ID'] ?? 0);

		if (!$userId || !isset(self::$currentUserFields[$userId]))
		{
			return;
		}

		/** @var array $currentUserFields */
		$currentUserFields = self::$currentUserFields[$userId];

		$isChangedActivity = isset($fields['ACTIVE']) && ($fields['ACTIVE'] !== $currentUserFields['ACTIVE']);

		$name = '';
		$lastName = '';

		$isChangedName = isset($fields['NAME']) && $fields['NAME'] !== $currentUserFields['NAME'];
		$isChangedLastName = isset($fields['LAST_NAME']) && $fields['LAST_NAME'] !== $currentUserFields['LAST_NAME'];

		$isChangedFields = $isChangedName || $isChangedLastName;
		if ($isChangedFields)
		{
			// login
			$login = $currentUserFields['LOGIN'];
			if (!empty($fields['LOGIN']))
			{
				$login = $fields['LOGIN'];
			}

			// name
			$name = $login;
			if (!empty($currentUserFields['NAME']))
			{
				$name = $currentUserFields['NAME'];
			}

			if (!empty($fields['NAME']))
			{
				$name = $fields['NAME'];
			}

			// last name
			$lastName = $login;
			if (!empty($currentUserFields['LAST_NAME']))
			{
				$lastName = $currentUserFields['LAST_NAME'];
			}

			if (!empty($fields['LAST_NAME']))
			{
				$lastName = $fields['LAST_NAME'];
			}
		}

		if ($isChangedActivity || $isChangedFields)
		{
			$user = self::getUser($userId);
			if (!$user || empty($user->clientId))
			{
				return;
			}

			if (SupersetInitializer::isSupersetReady())
			{
				if ($isChangedActivity)
				{
					self::changeActivity($user, $fields['ACTIVE'] === 'Y');
				}

				if ($isChangedFields)
				{
					self::updateUser($user, $name, $lastName);
				}

				self::setUpdated($user);
			}
			else
			{
				self::setNotUpdated($user);
			}
		}

		unset(self::$currentUserFields[$userId], $currentUserFields);
	}

	private static function changeActivity(Dto\User $user, bool $isActive): void
	{
		$integrator = Integrator::getInstance();

		Application::getInstance()->addBackgroundJob(function() use ($integrator, $user, $isActive) {
			if ($isActive)
			{
				$activeUserResult = $integrator->activateUser($user);
				if (!$activeUserResult->hasErrors())
				{
					(new Synchronizer($user->id))->sync();
				}
			}
			else
			{
				$activeUserResult = $integrator->deactivateUser($user);
				if (!$activeUserResult->hasErrors())
				{
					$integrator->setEmptyRole($user);
				}
			}

			if ($activeUserResult->hasErrors())
			{
				self::setNotUpdated($user);
			}
		});

		self::clearPermissionHash($user);
	}

	private static function updateUser(Dto\User $user, string $firstName, string $lastName): void
	{
		$user->firstName = $firstName;
		$user->lastName = $lastName;

		$integrator = Integrator::getInstance();

		Application::getInstance()->addBackgroundJob(function() use ($integrator, $user) {
			$updateResult = $integrator->updateUser($user);
			if ($updateResult->hasErrors())
			{
				self::setNotUpdated($user);
			}
		});
	}

	private static function getUser(int $userId): ?Dto\User
	{
		return (new SupersetUserRepository)->getById($userId);
	}

	private static function clearPermissionHash(Dto\User $user): void
	{
		SupersetUserTable::updatePermissionHash($user->id, '');
	}

	private static function setUpdated(Dto\User $user): void
	{
		SupersetUserTable::updateUpdated($user->id, true);
	}

	private static function setNotUpdated(Dto\User $user): void
	{
		SupersetUserTable::updateUpdated($user->id, false);
	}

	private static function isSupersetUser(int $userId): bool
	{
		return (bool)SupersetUserTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=USER_ID' => $userId,
			],
			'cache' => ['ttl' => 86400],
		]);
	}
}

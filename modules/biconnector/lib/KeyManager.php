<?php

namespace Bitrix\BIConnector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 *
 */
class KeyManager
{
	public const ERROR_EMPTY_DATA = 'EMPTY_DATA';
	public const ERROR_EMPTY_USER_ID = 'EMPTY_USER_ID';
	public const ERROR_KEY_NOT_FOUND = 'KEY_NOT_FOUND';

	private const DEFAULT_DATA = [
		'ID' => 0,
		'ACTIVE' => false,
		'USER_ID' => 0,
		'CONNECTION' => false,
		'USERS' => [],
	];

	/**
	 * Returns key fields array with default values set.
	 *
	 * @param array $data Access key fields.
	 * @return array
	 */
	private static function fillDefaultData(array $data): array
	{
		return array_merge(self::DEFAULT_DATA, $data);
	}

	/**
	 * Checks the key fields.
	 * Returns an empty collection if no errors was found.
	 *
	 * @param  mixed $data Access key fields.
	 *
	 * @return \Bitrix\Main\ErrorCollection
	 */
	private static function check(array $data): ErrorCollection
	{
		$errorCollection = new ErrorCollection();
		if (empty($data))
		{
			$errorCollection->setError(
				new Error('Empty key data', static::ERROR_EMPTY_DATA)
			);
		}

		if (($data['USER_ID'] ?? 0) <= 0)
		{
			$errorCollection->setError(
				new Error('Empty user id', static::ERROR_EMPTY_USER_ID)
			);
		}

		return $errorCollection;
	}

	/**
	 * Saves key with permission.
	 *
	 * @param array $data Array similar to self::DEFAULT_DATA.
	 *
	 * @return Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function save(array $data): Result
	{
		$errorCollection = static::check($data);
		if (!$errorCollection->isEmpty())
		{
			$result = new Result();
			$result->addErrors($errorCollection->getValues());

			return $result;
		}

		$data = static::fillDefaultData($data);
		$id = (int)$data['ID'];
		if ($id > 0)
		{
			return static::updateKey($id, $data);
		}

		return static::createKey($data);
	}

	/**
	 * Deletes a key and its user bindings.
	 *
	 * @param int $id Key identifier.
	 * @return Result
	 */
	public static function deleteKey(int $id): Result
	{
		if (!static::keyExists($id))
		{
			return static::createKeyNotFoundResult();
		}

		KeyUserTable::deleteByFilter(['=KEY_ID' => $id]);
		$deleteResult = KeyTable::delete($id);
		$result = new Result();
		if (!$deleteResult->isSuccess())
		{
			$result->addErrors($deleteResult->getErrors());

			return $result;
		}

		$result->setData(['ID' => $id]);

		return $result;
	}

	private static function createKey(array $data): Result
	{
		$result = new Result();
		if (empty($data['ACCESS_KEY']))
		{
			$data['ACCESS_KEY'] = static::generateAccessKey();
		}

		$save = static::prepareSaveData($data, true);
		$save['DATE_CREATE'] = new DateTime();
		$save['CREATED_BY'] = $data['USER_ID'];
		if (!empty($data['SERVICE_ID']))
		{
			$save['SERVICE_ID'] = $data['SERVICE_ID'];
		}

		$addResult = KeyTable::add($save);

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$id = (int)$addResult->getId();
		$usersResult = static::synchronizeUsers($id, $data, true);
		if (!$usersResult->isSuccess())
		{
			$result->addErrors($usersResult->getErrors());
			$cleanupResult = static::deleteKey($id);
			if (!$cleanupResult->isSuccess())
			{
				$result->addErrors($cleanupResult->getErrors());
			}

			return $result;
		}
		$result->setData(['ID' => $id]);

		return $result;
	}

	private static function updateKey(int $id, array $data): Result
	{
		if (!static::keyExists($id))
		{
			return static::createKeyNotFoundResult();
		}

		$result = new Result();
		$save = static::prepareSaveData($data, array_key_exists('ACCESS_KEY', $data));
		$updateResult = KeyTable::update($id, $save);
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());

			return $result;
		}

		$result->setData(['ID' => $id]);
		$usersResult = static::synchronizeUsers($id, $data, false);
		if (!$usersResult->isSuccess())
		{
			$result->addErrors($usersResult->getErrors());
		}

		return $result;
	}

	private static function keyExists(int $id): bool
	{
		return (bool)KeyTable::query()
			->setSelect(['ID'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
	}

	private static function createKeyNotFoundResult(): Result
	{
		$result = new Result();
		$result->addError(new Error('Key not found', static::ERROR_KEY_NOT_FOUND));

		return $result;
	}

	private static function prepareSaveData(array $data, bool $includeAccessKey): array
	{
		$save = [
			'TIMESTAMP_X' => new DateTime(),
			'ACTIVE' => $data['ACTIVE'] === true ? 'Y' : 'N',
		];
		if ($includeAccessKey)
		{
			$save['ACCESS_KEY'] = $data['ACCESS_KEY'];
		}

		if (isset($data['APP_ID']))
		{
			$save['APP_ID'] = (int)$data['APP_ID'] > 0 ? (int)$data['APP_ID'] : null;
		}

		$connections = Manager::getInstance()->getConnections();
		if ($data['CONNECTION'] && isset($connections[$data['CONNECTION']]))
		{
			$save['CONNECTION'] = $data['CONNECTION'];
		}
		else
		{
			$save['CONNECTION'] = key($connections);
		}

		return $save;
	}

	private static function synchronizeUsers(int $id, array $data, bool $isNewKey): Result
	{
		$result = new Result();
		if (!isset($data['USERS']))
		{
			return $result;
		}

		$usersForm = [];
		foreach ($data['USERS'] as $userId)
		{
			$userId = (int)trim($userId, " \t\n\r");
			if ($userId > 0)
			{
				$usersForm[$userId] = [
					'TIMESTAMP_X' => new DateTime(),
					'CREATED_BY' => $data['USER_ID'],
					'KEY_ID' => $id,
					'USER_ID' => $userId,
				];
			}
		}

		$usersDb = [];
		if (!$isNewKey)
		{
			$userList = KeyUserTable::query()
				->setSelect(['ID', 'USER_ID'])
				->where('KEY_ID', $id)
				->exec()
			;
			while ($user = $userList->fetch())
			{
				$usersDb[(int)$user['ID']] = (int)$user['USER_ID'];
			}
		}

		foreach ($usersDb as $dbId => $dbUserId)
		{
			if (isset($usersForm[$dbUserId]))
			{
				unset($usersForm[$dbUserId], $usersDb[$dbId]);
			}
		}

		foreach ($usersForm as $user)
		{
			$addResult = KeyUserTable::add($user);
			if (!$addResult->isSuccess())
			{
				$result->addErrors($addResult->getErrors());

				return $result;
			}
		}

		foreach ($usersDb as $dbId => $_)
		{
			$deleteResult = KeyUserTable::delete($dbId);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());

				return $result;
			}
		}

		return $result;
	}

	/**
	 * Returns new access key
	 * @return string
	 */
	public static function generateAccessKey(): string
	{
		return \Bitrix\Main\Security\Random::getStringByAlphabet(
			32,
			\Bitrix\Main\Security\Random::ALPHABET_NUM
			| \Bitrix\Main\Security\Random::ALPHABET_ALPHALOWER
			| \Bitrix\Main\Security\Random::ALPHABET_ALPHAUPPER
		);
	}

	protected static function createKeyInner(array $fields): Result
	{
		$key = self::generateAccessKey();
		$fields['ACCESS_KEY'] = $key;
		$resultSave = self::save($fields);
		if ($resultSave->isSuccess())
		{
			$resultSave->setData([
				...$resultSave->getData(),
				'ACCESS_KEY' => $key,
			]);
		}

		return $resultSave;
	}

	public static function createAccessKey(CurrentUser $user): Result
	{
		$keyParameters = [
			'USER_ID' => $user->getId(),
			'ACTIVE' => true,
		];

		return static::createKeyInner($keyParameters);
	}

	public static function getAccessKey(): ?string
	{
		$key = \Bitrix\BIConnector\KeyTable::getList([
			'select' => [
				'ACCESS_KEY',
			],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=APP_ID' => false,
			],
			'order' => [
				'ID' => 'DESC',
			],
			'limit' => 1,
		])->fetch();
		if ($key === false)
		{
			return null;
		}

		return $key['ACCESS_KEY'] ?? null;
	}

	public static function getOrCreateAccessKey(CurrentUser $user, $checkPermission = true): ?string
	{
		if (!$checkPermission || static::canManageKey($user))
		{
			$accessKey = \Bitrix\BIConnector\KeyManager::getAccessKey();
			if (!$accessKey)
			{
				$createdResult = \Bitrix\BIConnector\KeyManager::createAccessKey($user);
				if ($createdResult->isSuccess())
				{
					return $createdResult->getData()['ACCESS_KEY'] ?? null;
				}
			}
			else
			{
				return $accessKey;
			}
		}

		return null;
	}

	/**
	 * @param CurrentUser $user
	 * @return bool
	 */
	public static function canManageKey(CurrentUser $user): bool
	{
		return $user->canDoOperation('biconnector_key_manage');
	}
}

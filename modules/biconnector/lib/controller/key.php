<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\ActionFilter\KeyManageAccess;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\BIConnector\KeyManager;
use Bitrix\BIConnector\KeyTable;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\BIConnector\Superset\KeyManager as SupersetKeyManager;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Engine\ActionFilter\AuthType;

/**
 * Class Key
 * @package Bitrix\BIConnector\Controller
 */
class Key extends Controller
{
	private const ALLOW_FILTER_FIELDS = [
		'ID',
		'DATE_CREATE',
		'TIMESTAMP_X',
		'CREATED_BY',
		'ACCESS_KEY',
		'CONNECTION',
		'ACTIVE',
	];

	private const ALLOW_SELECT_FIELDS = [
		'ID',
		'ACTIVE',
		'DATE_CREATE',
		'TIMESTAMP_X',
		'CREATED_BY',
		'CONNECTION',
	];

	/**
	 * Adds new key.
	 *
	 * @param array $fields New key fields.
	 * @param \CRestServer $server Main rest response object.
	 *
	 * @return array|int
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function addAction($fields, \CRestServer $server)
	{
		// the key always belongs to the caller, USER_ID from the request is ignored
		$fields['USER_ID'] = $this->prepareUserId();
		$res = KeyManager::save(
			[
				'APP_ID' => $this->getAppId($server),
				'CONNECTION' => $fields['CONNECTION'] ?? '',
				'USER_ID' => $fields['USER_ID'],
				'ACTIVE' => ($fields['ACTIVE'] ?? 'N') === 'Y',
			],
		);
		if (!$res->isSuccess())
		{
			$result = $this->prepareErrorsForRest('ADD', $res->getErrorCollection());
		}
		else
		{
			$result = (int)($res->getData()['ID'] ?? 0);
		}

		return $result;
	}

	/**
	 * Returns list of key by app.
	 *
	 * @param array $order KeyTable::getList order parameter.
	 * @param array $filter KeyTable::getList filter parameter.
	 * @param array $select KeyTable::getList select parameter.
	 * @param int $offset KeyTable::getList offset parameter.
	 * @param int $limit KeyTable::getList limit parameter.
	 * @param \CRestServer|null $server Main rest response object.
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @see \Bitrix\BIConnector\KeyTable::getList
	 */
	public function listAction(
		array $order = [],
		array $filter = [],
		array $select = [],
		$offset = 0,
		$limit = 50,
		?\CRestServer $server = null
	)
	{
		$result = [];
		$appId = 0;
		if (!is_null($server))
		{
			$appId = $this->getAppId($server);
		}

		if ($appId > 0)
		{
			$filter = $this->prepareFilter($filter);
			$select = $this->prepareSelect($select);
			$filter['=APP_ID'] = $appId;

			$res = KeyTable::getList(
				[
					'order' => $order ?: [],
					'filter' => $filter,
					'select' => $select,
					'offset' => $offset,
					'limit' => $limit,
				]
			);
			while ($item = $res->fetch())
			{
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Updates key.
	 *
	 * @param int $id Key identifier.
	 * @param array $fields KeyTable fields.
	 * @param \CRestServer $server Main rest response object.
	 *
	 * @return array|int|string[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @see \Bitrix\BIConnector\KeyTable::update
	 */
	public function updateAction($id, $fields, \CRestServer $server)
	{
		$result = [
			'error' => 'KEY_NOT_FOUND',
			'error_description' => 'Key not found.',
		];

		if (!is_array($fields))
		{
			$fields = [];
		}

		$appId = $this->getAppId($server);
		if ($appId > 0)
		{
			$list = KeyTable::getList(
				[
					'filter' => [
						'=ID' => $id,
						'=APP_ID' => $appId,
					],
					'select' => [
						'ID',
					],
				]
			);
			if ($item = $list->fetch())
			{
				$save = [];
				if (array_key_exists('CONNECTION', $fields))
				{
					// a non-string value cannot name a connection and must not reach the array offset
					$connection = $fields['CONNECTION'];
					$connections = Manager::getInstance()->getConnections();
					if (!is_string($connection) || !isset($connections[$connection]))
					{
						return [
							'error' => 'WRONG_CONNECTION',
							'error_description' => 'Connection not found.',
						];
					}

					$save['CONNECTION'] = $connection;
				}
				if (array_key_exists('ACTIVE', $fields))
				{
					$save['ACTIVE'] = $fields['ACTIVE'] === 'Y';
				}

				$res = KeyTable::update(
					$item['ID'],
					$save
				);
				if (!$res->isSuccess())
				{
					$result = $this->prepareErrorsForRest('UPDATE', $res->getErrorCollection());
				}
				else
				{
					$result = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Deletes the key.
	 *
	 * @param int $id Key identifier.
	 * @param \CRestServer $server Main rest response object.
	 *
	 * @return array|bool|string[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function deleteAction($id, \CRestServer $server)
	{
		$result = [
			'error' => 'KEY_NOT_FOUND',
			'error_description' => 'Key not found.',
		];

		$appId = $this->getAppId($server);
		if ($appId > 0)
		{
			$list = KeyTable::getList(
				[
					'filter' => [
						'=ID' => $id,
						'=APP_ID' => $appId,
					],
					'select' => [
						'ID',
					],
				]
			);
			if ($item = $list->fetch())
			{
				$deleteResult = KeyManager::deleteKey((int)$item['ID']);
				if (!$deleteResult->isSuccess())
				{
					if (
						$deleteResult->getErrorCollection()->getErrorByCode(KeyManager::ERROR_KEY_NOT_FOUND) === null
					)
					{
						$result = $this->prepareErrorsForRest(
							'DELETE',
							$deleteResult->getErrorCollection(),
						);
					}
				}
				else
				{
					$result = true;
				}
			}
		}

		return $result;
	}

	/**
	 * prepareFilter
	 *
	 * @param array $filter KeyTable filter.
	 * @return array
	 */
	private function prepareFilter(array $filter): array
	{
		$result = [];
		foreach ($filter as $code => $value)
		{
			$filterType = '';
			$matches = [];
			if (preg_match('/^(\W{1,2})(.+)/', $code, $matches) && $matches[2])
			{
				$filterType = $matches[1];
				$code = $matches[2];
			}

			if (in_array($code, self::ALLOW_FILTER_FIELDS, true))
			{
				if ($code === 'USER_ID')
				{
					if (is_array($value))
					{
						foreach ($value as $k => $val)
						{
							$value[$k] = $this->prepareUserId((int)$val);
						}
						$value = array_unique($value);
					}
					else
					{
						$value = $this->prepareUserId((int)$value);
					}
				}

				$result[$filterType . $code] = $value;
			}
		}

		return $result;
	}

	/**
	 * prepareSelect
	 *
	 * @param array $select KeyTable select fields.
	 *
	 * @return array
	 */
	private function prepareSelect(array $select): array
	{
		$result = [];

		foreach ($select as $code)
		{
			if (in_array($code, self::ALLOW_SELECT_FIELDS, true))
			{
				$result[] = $code;
			}
		}

		return $result ?: self::ALLOW_SELECT_FIELDS;
	}

	/**
	 * prepareErrorsForRest
	 *
	 * @param string $errorCode Error code.
	 * @param \Bitrix\Main\ErrorCollection $errors Rest errors.
	 *
	 * @return array
	 */
	private function prepareErrorsForRest($errorCode, \Bitrix\Main\ErrorCollection $errors): array
	{
		$message = [];
		$code = '';
		foreach ($errors->getValues() as $error)
		{
			/** @var Error $error */
			$code = (string)$error->getCode();
			$mess = (string)$error->getMessage();
			if ($code !== '')
			{
				$mess = $code . ($mess !== '' ? ':' . $mess : '');
			}
			if ($mess !== '')
			{
				$message[] = $mess;
			}
		}

		return [
			'error' => count($message) > 1 ? $errorCode : $code,
			'error_description' => implode(', ', $message),
		];
	}

	/**
	 * getAppId
	 *
	 * @param \CRestServer $server Main rest response object.
	 *
	 * @return int
	 */
	private function getAppId(\CRestServer $server): int
	{
		$clientId = $server->getClientId();
		if ($clientId === null)
		{
			return 0;
		}

		$app = AppTable::getByClientId($clientId);

		return $app['ID'] ? (int)$app['ID'] : 0;
	}

	/**
	 * prepareUserId
	 *
	 * @param int $id User identifier.
	 *
	 * @return int
	 */
	private function prepareUserId($id = 0): int
	{
		if ($id === 0 || !\CRestUtil::isAdmin())
		{
			$id = 0;
			global $USER;
			if ($USER instanceof \CUser)
			{
				$id = (int)$USER->GetID();
			}
		}

		return $id;
	}

	/**
	 * @return string|null
	 */
	public function changeSupersetKeyAction(): ?string
	{
		$user = CurrentUser::get();
		if (!SupersetKeyManager::canManageKey($user))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$activeKeys = KeyTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=SERVICE_ID' => ApacheSuperset::getServiceId(),
					'=ACTIVE' => 'Y',
					'=APP_ID' => false,
				],
			])
			->fetchCollection()
		;

		$createResult = SupersetKeyManager::createAccessKey($user);
		if (!$createResult->isSuccess())
		{
			$this->addErrors($createResult->getErrors());

			return null;
		}

		$accessKey = $createResult->getData()['ACCESS_KEY'] ?? null;
		if (empty($accessKey))
		{
			$this->addError(new Error('Failed to create access key'));

			return null;
		}

		$integrator = IntegratorFactory::getInstance();
		$response = $integrator->changeBiconnectorToken($accessKey);
		if ($response->hasErrors())
		{
			SupersetKeyManager::deleteAccessKey($accessKey);

			$this->addError(new Error('Failed to update token in Superset'));

			return null;
		}
		$integrator->refreshDomainConnection();

		if (!$activeKeys->isEmpty())
		{
			foreach ($activeKeys as $key)
			{
				$key->delete();
			}
		}
		else
		{
			Option::delete('biconnector', ['name' => SupersetKeyManager::SUPERSET_KEY_OPTION_NAME]);
		}

		return $accessKey;
	}

	/**
	 * Returns array of rest filters.
	 *
	 * @return array
	 */
	public function getDefaultPreFilters()
	{
		return [
			...parent::getDefaultPreFilters(),
			new AuthType(AuthType::APPLICATION),
			new KeyManageAccess(),
		];
	}
}

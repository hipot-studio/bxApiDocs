<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;

class KeyManager extends BIConnector\KeyManager
{
	public const SUPERSET_KEY_OPTION_NAME = '~superset_key';

	public static function getAccessKey(): ?string
	{
		$key = BIConnector\KeyTable::getList([
				'select' => [
					'ACCESS_KEY',
				],
				'filter' => [
					'=SERVICE_ID' => ApacheSuperset::getServiceId(),
					'=ACTIVE' => 'Y',
					'=APP_ID' => false,
				],
				'limit' => 1,
			])
			->fetch()
		;

		if (empty($key['ACCESS_KEY']))
		{
			return Option::get('biconnector', self::SUPERSET_KEY_OPTION_NAME, null);
		}

		return $key['ACCESS_KEY'];
	}

	public static function createAccessKey(CurrentUser $user): Result
	{
		$keyParameters = [
			'USER_ID' => $user->getId(),
			'ACTIVE' => true,
			'SERVICE_ID' => ApacheSuperset::getServiceId(),
		];

		return static::createKeyInner($keyParameters);
	}

	public static function deleteKey(string $key): void
	{
		$key = BIConnector\KeyTable::getList([
			'select' => [
				'ID',
			],
			'filter' => [
				'=SERVICE_ID' => ApacheSuperset::getServiceId(),
				'=ACTIVE' => 'Y',
				'=APP_ID' => false,
				'=ACCESS_KEY' => $key,
			],
			'limit' => 1,
		])
			->fetchObject()
		;

		if ($key)
		{
			$key->delete();
		}
	}

	public static function isActiveKey(string $key): bool
	{
		$keysCount = BIConnector\KeyTable::getCount([
			'=SERVICE_ID' => ApacheSuperset::getServiceId(),
			'=ACTIVE' => 'Y',
			'=APP_ID' => false,
			'=ACCESS_KEY' => $key,
		]);

		return $keysCount > 0;
	}
}

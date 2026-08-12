<?php

namespace Bitrix\Superset\Public\Commands\Support;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Public\Support\ServerDtoFactory;

final class ServerResultDecorator
{
	public static function createServerContextResult(Server $server): Result
	{
		$dtoFactory = new ServerDtoFactory();
		$result = new Result();
		$result->setData([
			'server' => $dtoFactory->createRuntimeState($server),
			'server_reference' => $dtoFactory->createReference($server),
		]);

		return $result;
	}

	public static function decorateServerContextResult(Result $result, ?Server $fallbackServer = null): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$server = $data['serverEntity'] ?? $fallbackServer;
		if (!$server instanceof Server)
		{
			return $result;
		}

		unset($data['serverEntity']);
		$dtoFactory = new ServerDtoFactory();
		$data['server'] = $dtoFactory->createRuntimeState($server);
		$data['server_reference'] = $dtoFactory->createReference($server);
		$result->setData($data);

		return $result;
	}

	public static function decorateGeneratedKeysResult(Result $result, Server $server): Result
	{
		$result = self::decorateServerContextResult($result, $server);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$data['superset_address'] = (string)($server->getHost() ?? '');
		$data['jwt_public_key'] = (string)($data['publicKey'] ?? self::getJwtPublicKey($server));
		$result->setData($data);

		return $result;
	}

	private static function getJwtPublicKey(Server $server): string
	{
		$jwtSecret = $server->getJwtSecret() ?? '';
		if ($jwtSecret === '')
		{
			return '';
		}

		$privateKey = openssl_pkey_get_private($jwtSecret);
		if ($privateKey === false)
		{
			return '';
		}

		$details = openssl_pkey_get_details($privateKey);

		return base64_encode($details['key'] ?? '');
	}
}

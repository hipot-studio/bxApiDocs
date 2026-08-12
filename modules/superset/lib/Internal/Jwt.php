<?php

namespace Bitrix\Superset\Internal;

use Bitrix\Main;

final class Jwt
{
	public const TTL = 3600;

	public static function encode(array $data, string $secret): string
	{
		$time = time();
		$data['iat'] = $time;
		$data['exp'] = $time + self::TTL;

		return Main\Web\JWT::encode($data, $secret, 'RS256');
	}
}

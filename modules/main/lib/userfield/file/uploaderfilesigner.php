<?php

namespace Bitrix\Main\UserField\File;


use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Web\Json;

class UploaderFileSigner
{
	private const SALT = 'UploaderFileSigner';
	public function sign(array $controllerOptions): string
	{
		$value = Json::encode($controllerOptions);

		return (new Signer())->sign($value, self::SALT);
	}

	public function unsign(string $signed): array
	{
		try
		{
			$unsignedValue = (new Signer())->unsign($signed, self::SALT);

			return Json::decode($unsignedValue);
		}
		catch (BadSignatureException $e)
		{
			return [];
		}
	}
}

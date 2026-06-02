<?php

declare(strict_types=1);

namespace Bitrix\AI\Service;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Web\HttpClient;

final class BasicAuthToken
{
	private const CONFIG_KEY_USERNAME = 'x_ai_auth_username';
	private const CONFIG_KEY_PASSWORD = 'x_ai_auth_password';

	public static function getAuthorizationHeader(): ?string
	{
		$username = self::getConfigValue(self::CONFIG_KEY_USERNAME);
		$password = self::getConfigValue(self::CONFIG_KEY_PASSWORD);
		if ($username === '' || $password === '')
		{
			return null;
		}

		return 'Basic ' . base64_encode($username . ':' . $password);
	}

	public static function addHeaderIfAvailable(HttpClient $httpClient): void
	{
		$header = self::getAuthorizationHeader();
		if ($header !== null)
		{
			$httpClient->setHeader('Authorization', $header);
		}
	}

	private static function getConfigValue(string $key): string
	{
		$value = Configuration::getInstance('ai')->get($key);
		if (is_string($value) && $value !== '')
		{
			return $value;
		}

		$value = Configuration::getInstance()->get($key);

		return (is_string($value) && $value !== '') ? $value : '';
	}
}

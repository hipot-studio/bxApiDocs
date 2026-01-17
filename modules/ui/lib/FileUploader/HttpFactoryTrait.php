<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Web\HttpClient;

trait HttpFactoryTrait
{
	protected static array $defaultClientOptions = [
		'socketTimeout' => 10,
		'streamTimeout' => 30,
		'version' => HttpClient::HTTP_1_1,
	];

	protected static HttpClient|null $client = null;

	protected static function getClient(): HttpClient
	{
		if (self::$client === null)
		{
			self::$client = new HttpClient(self::$defaultClientOptions);
			self::$client->setPrivateIp(false);
		}
		return self::$client;
	}

	public static function setClient(HttpClient $client)
	{
		self::$client = $client;
		self::$client->setPrivateIp(false);
	}
}
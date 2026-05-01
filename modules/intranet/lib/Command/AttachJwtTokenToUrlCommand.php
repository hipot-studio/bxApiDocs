<?php

namespace Bitrix\Intranet\Command;

use Bitrix\Main\Context;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Config\Option;

class AttachJwtTokenToUrlCommand
{
	public function __construct(
		private readonly Uri $uri,
		private readonly string $token,
		private readonly string $parametrName = 'invite_token',
	)
	{}

	private static function getUri(): Uri
	{
		$serverName = Option::get('main', 'server_name');

		if (defined('BX24_HOST_NAME') && !empty(BX24_HOST_NAME))
		{
			$serverName = BX24_HOST_NAME;
		}
		elseif (defined('SITE_SERVER_NAME') && !empty(SITE_SERVER_NAME))
		{
			$serverName = SITE_SERVER_NAME;
		}

		$baseUrl = (Context::getCurrent()->getRequest()->isHttps() ? 'https://' : 'http://') . $serverName;
		$uri = new Uri($baseUrl);
		$path = SITE_DIR;
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			$path .= 'auth/registration_link.php';
			$uri->addParams(['register' => 'yes']);
		}
		$uri->setPath($path);

		return $uri;
	}

	public static function createDefaultInstance(string $token): self
	{
		$uri = self::getUri();

		return new self($uri, $token, 'invite_token');
	}

	public static function createInstanceWithParams(string $token, array $params): self
	{
		$uri = self::getUri();

		if (!empty($params))
		{
			foreach ($params as $key => $value)
			{
				$uri->addParams([$key => $value]);
			}
		}

		return new self($uri, $token, 'invite_token');
	}

	public function attach(): Uri
	{
		return $this->uri->addParams([
			$this->parametrName => $this->token,
		]);
	}
}

<?php

namespace Bitrix\Call;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Configuration;

class Library
{
	protected const SELF_TEST_UTL = [
		'ru' => 'https://calltest.bitrix24.ru/',
		'en' => 'https://calltest.bitrix24.com/',
	];

	public static function getClientSelfTestUrl(string $region = 'en'): string
	{
		$url = match (\Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: $region)
		{
			'ru','by','kz','uz' => self::SELF_TEST_UTL['ru'],
			default => self::SELF_TEST_UTL['en'],
		};
		$url .= '?hl='. \Bitrix\Main\Localization\Loc::getCurrentLang();

		return $url;
	}

	public static function getChatMessageUrl(int $chatId, int $messageId): string
	{
		return "/online/?IM_DIALOG=chat{$chatId}&IM_MESSAGE={$messageId}";
	}

	public static function getCallSliderUrl(int $callId): string
	{
		return "/call/detail/{$callId}";
	}

	public static function getCallAiFeedbackUrl(int $callId): string
	{
		return \Bitrix\Call\Integration\AI\CallAISettings::getFeedBackLink() ?? '';
	}

	/**
	 * Returns from settings or detects from request external public url.
	 *
	 * @return string
	 */
	public static function getPortalPublicUrl(): string
	{
		static $publicUrl;
		if ($publicUrl === null)
		{
			$publicUrl = Option::get('call', 'public_url', '');
			if (empty($publicUrl))
			{
				$publicUrl = Configuration::getInstance()->get('call')['public_url'] ?? '';
			}
			if (empty($publicUrl))
			{
				$publicUrl = \Bitrix\Main\Service\MicroService\Client::getServerName();
			}
			if (
				empty($publicUrl)
				|| !($parsedUrl = parse_url($publicUrl))
				|| empty($parsedUrl['host'])
			)
			{
				$context = Application::getInstance()->getContext();
				$scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
				$server = $context->getServer();
				$domain = $server->getServerName();
				if (preg_match('/^(?<domain>.+):(?<port>\d+)$/', $domain, $matches))
				{
					$domain = $matches['domain'];
					$port = (int)$matches['port'];
				}
				else
				{
					$port = (int)$server->getServerPort();
				}
				$port = in_array($port, [0, 80, 443]) ? '' : ':'.$port;

				$publicUrl = $scheme.'://'.$domain.$port;
			}
			if (!(mb_strpos($publicUrl, 'https://') === 0 || mb_strpos($publicUrl, 'http://') === 0))
			{
				$publicUrl = 'https://' . $publicUrl;
			}
		}

		return $publicUrl;
	}
}



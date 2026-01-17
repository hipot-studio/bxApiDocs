<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

namespace Bitrix\Main\Controller;

use Bitrix\Main;
use Bitrix\Main\Config;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\PullConfigTrait;
use Bitrix\Pull;

class QrCodeAuth extends Main\Engine\Controller
{
	use PullConfigTrait;

	public function isAllowed()
	{
		if (Config\Option::get('main', 'allow_qrcode_auth', 'N') !== 'Y')
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_option'), 'ERR_OPTION'));
			return false;
		}

		if (!Main\Loader::includeModule('pull') || !\CPullOptions::GetQueueServerStatus())
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_pull'), 'ERR_PULL'));
			return false;
		}

		return true;
	}

	public function pushTokenAction(string $siteId, string $uniqueId, string $channelTag, string $redirectUrl = '')
	{
		$this->pushToken($siteId, $uniqueId, $channelTag, $redirectUrl);
	}

	public function authenticateAction($token, bool $remember = false)
	{
		global $USER;

		if ($token == '')
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_request'), "ERR_PARAMS"));
			return null;
		}

		if (!$USER->LoginHitByHash($token, false, true, $remember))
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_auth'), "ERR_AUTH"));
			return null;
		}

		return true;
	}

	/**
	 * Adds a token and sends a message to p&p.
	 *
	 * @param string $siteId
	 * @param string $uniqueId
	 * @param string $channelTag
	 * @param string $redirectUrl
	 * @param string|null $currentUrl
	 *
	 * @return bool|null
	 */
	public function pushToken(string $siteId, string $uniqueId, string $channelTag, string $redirectUrl = '', ?string $currentUrl = null)
	{
		if ($siteId == '' || $uniqueId == '' || $channelTag == '')
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_request'), 'ERR_PARAMS'));

			return null;
		}

		$event = new \Bitrix\Main\Event(
			'main',
			'OnPushQrCodeAuthToken',
			[
				'siteId' => $siteId,
				'uniqueId' => $uniqueId,
				'channelTag' => $channelTag,
				'redirectUrl' => $redirectUrl,
			]
		);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === Main\EventResult::ERROR)
			{
				$error = $eventResult->getParameters()['error'] ?? null;
				if ($error instanceof Main\Error)
				{
					$this->addError($error);
				}
				if (is_string($error) && $error !== '')
				{
					$this->addError(new Main\Error($error, 'ERR_FROM_EVENT'));
				}

				return null;
			}

			return true;
		}

		if ($uniqueId !== static::getUniqueId())
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_unique_id'), 'ERR_UNIQUE_ID'));

			return null;
		}

		if (!$this->isAllowed())
		{
			return null;
		}

		$channel = Pull\Model\Channel::createWithTag($channelTag);

		$url = $currentUrl ?? Main\Context::getCurrent()->getRequest()->getRequestedPage();

		$token = \CUser::GetHitAuthHash($url, false, $siteId);
		if ($token === false)
		{
			$token = \CUser::AddHitAuthHash($url, false, $siteId, 60);
		}

		if ($token === false)
		{
			$this->addError(new Main\Error(Loc::getMessage('qrcodeauth_error_cant_get_token'), 'ERR_GET_TOKEN'));

			return null;
		}

		Pull\Event::add(
			[$channel],
			[
				'module_id' => 'main',
				'command' => 'qrAuthorize',
				'expiry' => 60,
				'params' => [
					'token' => $token,
					'redirectUrl' => $redirectUrl,
				],
			]
		);

		return true;
	}

	public function configureActions()
	{
		return [
			'authenticate' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\Authentication::class,
				],
			],
		];
	}
}

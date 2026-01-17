<?php

namespace Bitrix\Mobile\Helpers;

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Security\Mfa\Otp;
use CBitrix24;
use CHTTP;

class Auth
{
	public static function getOtpSocketConfig($config): array
	{
		$result = [
			'jsonRpc' => false,
			'url' => null,
		];

		$isJsonRpc = (isset($config['server']['version']) && $config['server']['version'] >= 5);
		$request = Context::getCurrent()->getRequest();
		$isSecure = $request->isHttps();
		$path = $isSecure ? $config['server']['websocket_secure'] : $config['server']['websocket'];
		if ($path)
		{
			if (isset($config['jwt']) && is_string($config['jwt']) && $config['jwt'] !== '')
			{
				$path = $path . '?token=' . $config['jwt'];
			}
			else
			{
				$channels = [];
				if (isset($config['channels']) && is_array($config['channels']))
				{
					foreach ($config['channels'] as $type => $channel)
					{
						if (!isset($channel['id']))
						{
							continue;
						}
						$channels[] = $channel['id'];
					}
				}

				$path = $path . '?CHANNEL_ID=' . urlencode(implode('/', $channels));
			}

			if (isset($config['server']['mode']) && $config['server']['mode'] === "shared" && isset($config['clientId']))
			{
				$path = $path . "&clientId=" . $config['clientId'];
			}

			if ($isJsonRpc)
			{
				$path = $path . "&jsonRpc=true";
			}
			else
			{
				$path = $path . "&binaryMode=true";
			}

			$result['jsonRpc'] = $isJsonRpc;
			$result['url'] = $path;
		}

		return $result;
	}

	public static function handleSecurityData(): array
	{
		global $APPLICATION, $USER;

		$userData = CHTTP::ParseAuthRequest();
		$login = $userData["basic"]["username"];
		if (!$login)
		{
			$login = $_REQUEST["USER_LOGIN"] ?? '';
		}

		if ($USER->isAuthorized() || !$login)
		{
			return [];
		}

		$result = [];

		if (Loader::includeModule('bitrix24') && ($captchaInfo = CBitrix24::getStoredCaptcha()))
		{
			$result["captchaCode"] = $captchaInfo["captchaCode"];
			$result["captchaURL"] = $captchaInfo["captchaURL"];
		}
		elseif ($APPLICATION->NeedCAPTHAForLogin($login))
		{
			$result["captchaCode"] = $APPLICATION->CaptchaGetCode();
		}

		//is recovery code was sent
		$didUserSendRecoveryCode = isset($_POST["USER_OTP"]) && isset($_POST["TYPE"]) && $_POST["TYPE"] == 'OTP';
		if ($didUserSendRecoveryCode)
		{
			if (!$result["captchaCode"])
			{
				$result["error"] = ['message' => "Failed to authorize with OTP.", 'code' => 'WRONG_OTP'];
			}
		}
		else
		{
			if (Loader::includeModule("security") && Otp::isOtpRequired())
			{
				$result["needOtp"] = true;
				if ($otpParams = Otp::getDeferredParams())
				{
					$result["otpType"] = $otpParams['OTP_TYPE'];
				}
			}
		}

		return $result;
	}
}
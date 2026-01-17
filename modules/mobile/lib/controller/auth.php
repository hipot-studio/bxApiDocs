<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Security\Controller\PushOtp;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;

class Auth extends Controller
{
	public function sendOtpRequestAction(): array
	{
		$result = [];
		if (Loader::includeModule("security") && Otp::isOtpRequired())
		{
			$otpParams = Otp::getDeferredParams();
			$result["otpType"] = $otpParams['OTP_TYPE'];

			if (empty($otpParams['OTP_TYPE']))
			{
				$this->addError(new Error(0, "OTP type is not specified"));
			}
			elseif ($otpParams['OTP_TYPE'] !== OtpType::Push->value)
			{
				$this->addError(new Error(1, "Wrong OTP type"));
			}
			else
			{
				$config = PushOtp::getPullConfig();
				$controller = new PushOtp();
				if ($controller->sendMobilePushAction($config['channelTag']) === null)
				{
					foreach ($controller->getErrors() as $error)
					{
						$this->addError($error);
					}
				}
				else
				{
					$result['otpSocketConfig'] = \Bitrix\Mobile\Helpers\Auth::getOtpSocketConfig($config['pullConfig']);
					$result["otpDevice"] = $otpParams['DEVICE_INFO'] ?? null;
				}
			}
		}
		else
		{
			$this->addError(new Error(2, "OTP is not required"));
		}

		return $result;
	}

	public
	function configureActions()
	{
		return [
			'sendOtpRequest' => [
				'-prefilters' => [
					Authentication::class,
				],
			]
		];
	}
}
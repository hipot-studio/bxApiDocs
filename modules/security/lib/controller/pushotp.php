<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Security\Controller;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\PullConfigTrait;
use Bitrix\Main\Text\Base32;
use Bitrix\Main\Service\GeoIp;
use Bitrix\Main\Web\UserAgent\Browser;
use Bitrix\Main\Web\UserAgent\DeviceType;
use Bitrix\Main\Security\Mfa\TotpAlgorithm;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Pull;
use Bitrix\Pull\Model\PushTable;
use Bitrix\Main\PhoneNumber;

class PushOtp extends Main\Engine\Controller
{
	protected const SMS_RESEND_INTERVAL = 60;
	protected const SMS_TEMPLATE = 'SMS_USER_OTP_AUTH_CODE';

	use PullConfigTrait;

	public function initCodeAction(string $uniqueId, string $channelTag, string $code, string $secret, ?array $device = null)
	{
		if ($secret == '')
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_request'), 'REQUEST'));
			return null;
		}

		return $this->sendCodeAction($uniqueId, $channelTag, $code, $secret, $device);
	}

	public function sendCodeAction(string $uniqueId, string $channelTag, string $code, ?string $secret = null, ?array $device = null)
	{
		if ($uniqueId == '' || $channelTag == '' || $code == '')
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_request'), 'REQUEST'));
			return null;
		}

		if ($uniqueId !== static::getUniqueId())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_unique_id'), 'UNIQUE_ID'));
			return null;
		}

		if (!Otp::isPushPossible())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_available'), 'PUSH_OTP_DISABLED'));
			return null;
		}

		$channel = Pull\Model\Channel::createWithTag($channelTag);

		$params = [
			'code' => $code,
		];
		if ($secret !== null)
		{
			$params['secret'] = bin2hex(Base32::decode($secret));
		}
		if ($device !== null)
		{
			$params['device'] = $device;
		}

		Pull\Event::add(
			[$channel],
			[
				'module_id' => 'security',
				'command' => 'pushOtpCode',
				'expiry' => 60,
				'params' => $params,
			]
		);

		return true;
	}

	public function sendMobilePushAction(string $channelTag)
	{
		if ($channelTag == '')
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_request'), 'REQUEST'));
			return null;
		}

		if (!Otp::isPushPossible())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_available'), 'PUSH_OTP_DISABLED'));
			return null;
		}

		$otpParams = $this->getOtpParams();

		if ($otpParams === null)
		{
			return null;
		}

		$geoData = $this->getGeoData();
		$browserData = $this->getBrowserData();

		$devices = [];
		if (!empty($otpParams['DEVICE_INFO']['id']))
		{
			$devices = PushTable::getList([
				'filter' => [
					"=USER_ID" => $otpParams['USER_ID'],
					"=DEVICE_ID" => $otpParams['DEVICE_INFO']['id'],
				],
			])->fetchAll();
		}

		$manager = new \CPushManager();
		$manager->sendMessage(
			[[
				'USER_ID' => $otpParams['USER_ID'],
				'APP_ID' => "Bitrix24",
				'TITLE' => Loc::getMessage('push_otp_controller_push_title'),
				'MESSAGE' => Loc::getMessage('push_otp_controller_push_message_msgver_1'),
				'PARAMS' => [
					'type' => '2FA',
					'channelTag' => $channelTag,
					'geoData' => $geoData,
					'browser' => $browserData,
				],
			]],
			$devices
		);

		$result = Pull\Event::add(
			$otpParams['USER_ID'],
			[
				'module_id' => 'security',
				'command' => '2FA',
				'params' => [
					'channelTag' => $channelTag,
					'geoData' => $geoData,
					'browser' => $browserData,
			],
		]);

		if (!$result)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_pull_err'), 'PULL'));
			return null;
		}

		return true;
	}

	public function sendSmsAction()
	{
		if (!Otp::isPushPossible())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_available'), 'PUSH_OTP_DISABLED'));
			return null;
		}

		$otpParams = $this->getOtpParams();

		if ($otpParams === null)
		{
			return null;
		}

		$code = $this->getOtpCode($otpParams['USER_ID']);

		if ($code === null)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_active'), 'OTP_CODE'));
			return null;
		}

		$userPhone = Main\UserPhoneAuthTable::getList([
			'select' => ['PHONE_NUMBER', 'DATE_SENT', 'USER.LANGUAGE_ID', 'USER.LID'],
			'filter' => [
				'=USER_ID' => $otpParams['USER_ID'],
				'=CONFIRMED' => 'Y',
			],
		])->fetchObject();

		if (!$userPhone)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_phone_not_found'), 'PHONE_NOT_FOUND'));
			return null;
		}

		// allowed only once in a minute
		if ($userPhone->getDateSent())
		{
			$currentDateTime = new Main\Type\DateTime();
			$timePassed = $currentDateTime->getTimestamp() - $userPhone->getDateSent()->getTimestamp();

			if ($timePassed < static::SMS_RESEND_INTERVAL)
			{
				$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_sms_timeout'), "SMS_TIMEOUT"));
				return [
					'timeLeft' => static::SMS_RESEND_INTERVAL - $timePassed,
				];
			}
		}

		$sms = new Main\Sms\Event(
			static::SMS_TEMPLATE,
			[
				'USER_PHONE' => $userPhone->getPhoneNumber(),
				'CODE' => $code,
			]
		);

		$siteId = Main\Context::getCurrent()->getSite() ?? \CSite::GetDefSite($userPhone->getUser()->getLid());
		$sms->setSite($siteId);

		$language = $userPhone->getUser()->getLanguageId();
		if ($language != '')
		{
			//user preferred language
			$sms->setLanguage($language);
		}

		$result = $sms->send(true);

		Main\UserPhoneAuthTable::update($otpParams['USER_ID'], [
			'DATE_SENT' => new Main\Type\DateTime(),
		]);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		$number = PhoneNumber\Parser::getInstance()->parse($userPhone->getPhoneNumber());

		return [
			'maskedPhoneNumber' => PhoneNumber\Formatter::mask($number),
			'timeLeft' => static::SMS_RESEND_INTERVAL,
		];
	}

	protected function getOtpCode(int $userId): ?string
	{
		$otp = Otp::getByUser($userId);

		if ($otp->isActivated() && $otp->isUserActive())
		{
			$algo = $otp->getAlgorithm();

			//code value only for TOTP
			if ($algo instanceof TotpAlgorithm)
			{
				//value based on the current time
				$timeCode = $algo->timecode(time());

				return $algo->generateOTP($timeCode);
			}
		}

		return null;
	}

	protected function getOtpParams(): ?array
	{
		$otpParams = Otp::getDeferredParams();

		if (empty($otpParams['USER_ID']) || empty($otpParams['OTP_TYPE']) || $otpParams['OTP_TYPE'] !== OtpType::Push->value)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_params'), 'OTP_PARAMS'));
			return null;
		}

		return $otpParams;
	}

	protected function getGeoData(): array
	{
		$geoData = [];
		$ip = GeoIp\Manager::getRealIp();
		$ipData = GeoIp\Manager::getDataResult($ip, '', ['cityGeonameId']);

		if ($ipData && $ipData->isSuccess())
		{
			$data = $ipData->getGeoData();

			$geoData = [
				'ip' => $data->ip,
				'latitude' => $data->latitude,
				'longitude' => $data->longitude,
				'countryCode' => $data->countryCode ?? '',
			];

			$countries = \GetCountries();
			$geoData['countryName'] = $countries[$geoData['countryCode']]['NAME'] ?? $data->countryName ?? '';

			if (!empty($data->geonames))
			{
				$langCode = Main\Context::getCurrent()->getLanguageObject()->getCode();
				$region = $data->subRegionGeonameId ?? $data->regionGeonameId ?? 0;

				$geoData['regionName'] = $data->geonames[$region][$langCode] ?? $data->geonames[$region]['en'] ?? '';
				$geoData['cityName'] = $data->geonames[$data->cityGeonameId][$langCode] ?? $data->geonames[$data->cityGeonameId]['en'] ?? '';
			}
			else
			{
				$geoData['regionName'] = $data->subRegionName ?? $data->regionName ?? '';
				$geoData['cityName'] = $data->cityName ?? '';
			}
		}

		return $geoData;
	}

	protected function getBrowserData(): array
	{
		$deviceTypes = DeviceType::getDescription();
		$browser = Browser::detect();

		return [
			'deviceType' => $browser->getDeviceType(),
			'deviceTypeName' => $deviceTypes[$browser->getDeviceType()],
			'browserName' => $browser->getName(),
			'platform' => $browser->getPlatform(),
			'userAgent' => mb_substr($browser->getUserAgent(), 0, 500),
		];
	}

	public function configureActions()
	{
		return [
			'sendMobilePush' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\Authentication::class,
				],
			],
			'sendCode' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\Authentication::class,
				],
			],
			'sendSms' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\Authentication::class,
				],
			],
		];
	}
}

<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Security\Controller;

use Bitrix\Main;
use Bitrix\Main\Mail;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\PullConfigTrait;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Text\Base32;
use Bitrix\Main\Service\GeoIp;
use Bitrix\Main\Web\UserAgent\Browser;
use Bitrix\Main\Web\UserAgent\DeviceType;
use Bitrix\Main\Security\Mfa\TotpAlgorithm;
use Bitrix\Main\Authentication;
use Bitrix\Main\Authentication\ShortCode;
use Bitrix\Main\Component\ParameterSigner;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpType;
use Bitrix\Pull;
use Bitrix\Pull\Model\PushTable;
use Bitrix\Main\PhoneNumber;
use Bitrix\UI\Util;

class PushOtp extends Main\Engine\Controller
{
	protected const SMS_RESEND_INTERVAL = 60;
	protected const EMAIL_RESEND_INTERVAL = 60;
	protected const SMS_TEMPLATE = 'SMS_USER_OTP_AUTH_CODE';
	protected const EMAIL_EVENT = 'USER_OTP_AUTH_CODE';
	protected const EMAIL_CONFIRM_EVENT = 'USER_OTP_EMAIL_CONFIRM';
	protected const SIGNATURE_SALT = 'push_otp_email';

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
					'targetDeviceId' => $otpParams['DEVICE_INFO']['id'] ?? null,
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
		if (!Otp::isSmsEnabled())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_sms_disabled'), 'SMS_DISABLED'));
			return null;
		}

		if (!Otp::isPushPossible())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_available'), 'PUSH_OTP_DISABLED'));
			return null;
		}

		$otp = $this->getOtp();
		if ($otp === null)
		{
			return null;
		}

		$code = $this->getOtpCode($otp);
		if ($code === null)
		{
			return null;
		}

		$userPhone = Main\UserPhoneAuthTable::getList([
			'select' => ['PHONE_NUMBER', 'DATE_SENT', 'USER.LANGUAGE_ID', 'USER.LID'],
			'filter' => [
				'=USER_ID' => $otp->getUserId(),
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

		Main\UserPhoneAuthTable::update($userPhone->getUserId(), [
			'DATE_SENT' => new Main\Type\DateTime(),
		]);

		// write to the log ("on" by default)
		if (Option::get('security', 'otp_log_sending') != 'N')
		{
			\CSecurityEvent::getInstance()->doLog('SECURITY', 'SECURITY_OTP_SENDING', $userPhone->getUserId(), $userPhone->getPhoneNumber());
		}

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

	protected function checkEmailSettings(): bool
	{
		if (!Otp::isEmailEnabled())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_email_disabled'), 'EMAIL_DISABLED'));
			return false;
		}

		if (!Otp::isPushPossible())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_available'), 'PUSH_OTP_DISABLED'));
			return false;
		}

		return true;
	}

	public function sendEmailAction()
	{
		if (!$this->checkEmailSettings())
		{
			return null;
		}

		$otp = $this->getOtp();
		if ($otp === null)
		{
			return null;
		}

		if (empty($otp->getEmail()))
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_email_not_found'), 'EMAIL_NOT_FOUND'));
			return null;
		}

		$code = $this->getOtpCode($otp);
		if ($code === null)
		{
			return null;
		}

		// allowed only once in a minute
		$currentDateTime = new Main\Type\DateTime();
		if ($otp->getDateSentEmail())
		{
			$timePassed = $currentDateTime->getTimestamp() - $otp->getDateSentEmail()->getTimestamp();

			if ($timePassed < static::EMAIL_RESEND_INTERVAL)
			{
				$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_sms_timeout'), "EMAIL_TIMEOUT"));
				return [
					'timeLeft' => static::EMAIL_RESEND_INTERVAL - $timePassed,
				];
			}
		}

		$geoData = $this->getGeoData();
		$browserData = $this->getBrowserData();

		$fields = [
			'USER_ID' => $otp->getUserId(),
			'EMAIL' => $otp->getEmail(),
			'LOGIN' => $otp->getUserLogin(),
			'CODE' => $code,
			'DATE' => (string)$currentDateTime,
			'DEVICE' => $browserData['deviceTypeName'],
			'BROWSER' => $browserData['browserName'],
			'PLATFORM' => $browserData['platform'],
			'DEVICE_INFO' => implode(', ', array_filter([$browserData['deviceTypeName'], $browserData['browserName'], $browserData['platform']])),
			'USER_AGENT' => $browserData['userAgent'],
			'IP' => $geoData['ip'] ?? '',
			'COUNTRY' => $geoData['countryName'] ?? '',
			'REGION' => $geoData['regionName'] ?? '',
			'CITY' => $geoData ['cityName'] ?? '',
			'LOCATION' => implode(', ', array_filter([$geoData['cityName'] ?? '', $geoData['regionName'] ?? '', $geoData['countryName'] ?? ''])),
			'HELP_URL' => Util::getArticleUrlByCode('26937636'),
		];

		$context = Main\Context::getCurrent();
		$siteId = $context->getSite() ?? \CSite::GetDefSite();
		$language = $context->getLanguage();

		$result = Mail\Event::sendImmediate([
			'EVENT_NAME' => static::EMAIL_EVENT,
			'C_FIELDS' => $fields,
			'LID' => $siteId,
			'LANGUAGE_ID' => $language,
		]);

		$otp->setDateSentEmail($currentDateTime)
			->save()
		;

		// write to the log ("on" by default)
		if (Option::get('security', 'otp_log_sending') != 'N')
		{
			\CSecurityEvent::getInstance()->doLog('SECURITY', 'SECURITY_OTP_SENDING', $otp->getUserId(), $otp->getEmail());
		}

		if ($result != Mail\Event::SEND_RESULT_SUCCESS)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_email_error'), 'EMAIL_ERROR'));
			return null;
		}

		return [
			'maskedEmail' => $this->maskEmail($otp->getEmail()),
			'timeLeft' => static::EMAIL_RESEND_INTERVAL,
		];
	}

	public function sendConfirmationEmailAction(string $email)
	{
		global $USER;

		if (!$this->checkEmailSettings())
		{
			return null;
		}

		if (empty($email) || !check_email($email, true))
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_email'), 'INCORRECT_EMAIL'));
			return null;
		}

		$context = new Authentication\Context();
		$context->setUserId($USER->GetID());

		$shortCode = new ShortCode($context);

		//alowed only once in a minute
		$check = $shortCode->checkDateSent();

		if (!$check->isSuccess())
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_sms_timeout'), "EMAIL_TIMEOUT"));
			return [
				'timeLeft' => $check->getData()['secondsLeft'],
			];
		}

		$fields = [
			'USER_ID' => $USER->GetID(),
			'EMAIL' => $email,
			'LOGIN' => $USER->GetLogin(),
			'CODE' => $shortCode->generate(),
			'DATE' => (string)(new Main\Type\DateTime()),
		];

		$context = Main\Context::getCurrent();
		$siteId = $context->getSite() ?? \CSite::GetDefSite();
		$language = $context->getLanguage();

		$result = Mail\Event::sendImmediate([
			'EVENT_NAME' => static::EMAIL_CONFIRM_EVENT,
			'C_FIELDS' => $fields,
			'LID' => $siteId,
			'LANGUAGE_ID' => $language,
		]);

		$shortCode->saveDateSent();

		if ($result != Mail\Event::SEND_RESULT_SUCCESS)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_email_error'), 'EMAIL_ERROR'));
			return null;
		}

		return [
			'signedData' => ParameterSigner::signParameters(
				self::SIGNATURE_SALT,
				[
					'email' => $email,
				]
			),
			'timeLeft' => $check->getData()['resendInterval'],
		];
	}

	public function confirmEmailAction(string $code, string $signedData)
	{
		global $USER;

		if (!$this->checkEmailSettings())
		{
			return null;
		}

		try
		{
			$params = ParameterSigner::unsignParameters(self::SIGNATURE_SALT, $signedData);
		}
		catch (Main\SystemException)
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_request'), 'SIGNATURE'));
			return null;
		}

		if (empty($params['email']))
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_email'), 'INCORRECT_EMAIL'));
			return null;
		}

		if (!preg_match('/^[0-9]{6}$/', $code))
		{
			$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_incorrect_code'), 'INCORRECT_CODE'));
			return null;
		}

		$context = new Authentication\Context();
		$context->setUserId($USER->GetID());

		$shortCode = new ShortCode($context);

		$result = $shortCode->verify($code);

		if (!$result->isSuccess())
		{
			//replace the error message with the more specific one
			$errors = $result->getErrorCollection();
			if ($errors->getErrorByCode('ERR_CONFIRM_CODE') !== null)
			{
				$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_wrong_code'), 'ERR_CONFIRM_CODE'));
			}
			if ($errors->getErrorByCode('ERR_RETRY_COUNT') !== null)
			{
				$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_attempts_count'), 'ERR_RETRY_COUNT'));
			}
			return null;
		}

		Otp::getByUser($USER->GetID())
			->setEmail($params['email'])
			->save()
		;
	}

	protected function maskEmail(string $email): string
	{
		$emailParts = explode('@', $email);
		$domainParts = explode('.', $emailParts[1]);

		return mb_substr($emailParts[0], 0, 1)
			. '***'
			. (mb_strlen($emailParts[0]) > 3 ? mb_substr($emailParts[0], -1) : '')
			. '@***'
			. (count($domainParts) > 1 ? '.' . end($domainParts) : mb_substr($emailParts[1], -2))
		;
	}

	protected function getOtp(): ?Otp
	{
		$otpParams = $this->getOtpParams();
		if ($otpParams === null)
		{
			return null;
		}

		return Otp::getByUser($otpParams['USER_ID']);
	}

	protected function getOtpCode(Otp $otp): ?string
	{
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

		$this->addError(new Main\Error(Loc::getMessage('push_otp_controller_otp_not_active'), 'OTP_CODE'));

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
					ActionFilter\Authentication::class,
				],
			],
			'sendCode' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
				],
			],
			'sendSms' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
				],
			],
			'sendEmail' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
				],
			],
		];
	}
}

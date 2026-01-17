<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Main\OtpSigner;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Analytics;
use Bitrix\Mobile\Deeplink;
use Bitrix\Security\Controller\PushOtp;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\OtpException;
use Bitrix\Security\Mfa\OtpType;

class PersonalOtp
{
	private Otp $securityOtp;
	private const BASE_CACHE_DIR = '/otp/user_id/';
	private const CACHE_ID_PREFIX = 'user_otp_v3_';
	private UserOtp $otpInfo;

	/**
	 * @throws LoaderException
	 * @throws ArgumentTypeException|ArgumentOutOfRangeException|SystemException
	 */
	public function __construct(
		private readonly User $user,
	) {
		if (!Loader::includeModule('security'))
		{
			throw new SystemException('Module security is not installed');
		}

		$this->initOtpInfo();
	}

	/**
	 * @throws OtpException
	 * @throws ArgumentTypeException
	 */
	public function setup(string $secret, string $totpCode, OtpType $otpType, array $initParams = []): void
	{
		if (preg_match("/[^[:xdigit:]]/i", $secret))
		{
			$binarySecret = $secret;
		}
		else
		{
			$binarySecret = pack('H*', $secret);
		}

		$this->getOtpByUser()
			->regenerate($binarySecret)
			->setType($otpType)
			->setInitParams($initParams)
			->syncParameters($totpCode)
			->save()
		;
	}

	/**
	 * @throws OtpException
	 * @throws ArgumentTypeException
	 */
	public function activate(): void
	{
		$this->getOtpByUser()->activate();
	}

	/**
	 * @throws OtpException
	 * @throws ArgumentTypeException
	 */
	public function deactivate($days = 0): void
	{
		if (
			$days === 0
			&& $this->isPushType()
			&& MobilePush::createByDefault()->getPromoteMode() === PromoteMode::High
		)
		{
			return;
		}

		$this->getOtpByUser()->deactivate($days);
		(new Analytics\AnalyticsEvent(event: 'turnoff_2fa_employee_temp', tool: 'settings', category: 'security'))->send();
	}

	public function getType(): OtpType
	{
		return $this->otpInfo->type;
	}

	public function isActivated(): bool
	{
		return $this->otpInfo->isActive;
	}

	public function canSkipMandatory(): bool
	{
		return $this->otpInfo->canSkipMandatory;
	}

	public function canSkipMandatoryByRights(): bool
	{
		return $this->otpInfo->canSkipMandatoryByRights;
	}

	public function isPushType(): bool
	{
		if (!$this->otpInfo->isInitialized)
		{
			return false;
		}

		return $this->otpInfo->type === OtpType::Push;
	}

	public function getDeactivateUntil(): ?DateTime
	{
		return $this->otpInfo->dateDeactivate;
	}

	public function getGracePeriod(): ?DateTime
	{
		$mobilePush = MobilePush::createByDefault();

		if (!$this->isPushType() && $mobilePush->gracePeriodEnabled())
		{
			return DateTime::createFromTimestamp($mobilePush->getGracePeriod());
		}

		return $this->getDeactivateUntil();
	}

	public function getOtpConfig(): array
	{
		$config = PushOtp::getPullConfig();
		$intent = 'pushOtpInit/' . $config['channelTag'];
		$verifyPhone = new VerifyPhoneService($this->user);
		$phoneConfirmed = $verifyPhone->isConfirmed(new Phone($this->user->getAuthPhoneNumber() ?? ''));

		return [
			'pullConfig' =>  $config['pullConfig'] ?? [],
			'ttl' => 600,
			'intent' => $intent,
			'phoneNumber' => $this->user->getAuthPhoneNumber(),
			'isPhoneNumberConfirmed' => $phoneConfirmed,
			'signedUserId' => (new OtpSigner())->signUserId($this->user->getId()),
		];
	}

	public function getDeeplink(string $intent, int $ttl = 600): string
	{
		if (Loader::includeModule('mobile'))
		{
			return Deeplink::getAuthLink($intent, $this->user->getId(), $ttl);
		}

		return '';
	}

	public function getInitParams(): array
	{
		return $this->otpInfo->initParams;
	}

	public function isInitialized(): bool
	{
		return $this->otpInfo->isInitialized;
	}

	public function isRequired(): bool
	{
		return !$this->canSkipMandatoryByRights();
	}

	public function getOtpInfo(): UserOtp
	{
		return $this->otpInfo;
	}

	/**
	 * @throws ArgumentTypeException
	 */
	private function initOtpInfo(): void
	{
		$ttl = defined('BX_COMP_MANAGED_CACHE') ? 2592000 : 600;
		$cacheId = self::CACHE_ID_PREFIX . $this->user->getId();
		$cacheDir = self::BASE_CACHE_DIR . substr(md5((string)$this->user->getId()), -2) . '/' . $this->user->getId() . '/';
		$cache = Application::getInstance()->getCache();

		if ($cache->InitCache($ttl, $cacheId, $cacheDir))
		{
			$otpData = $cache->GetVars();
			$this->otpInfo = UserOtp::initByArray($otpData);
		}
		else
		{
			$this->otpInfo = new UserOtp(
				userId: $this->user->getId(),
				isActive: $this->getOtpByUser()->isActivated(),
				dateDeactivate: $this->getOtpByUser()->getDeactivateUntil(),
				isInitialized: $this->getOtpByUser()->isInitialized(),
				type: $this->getOtpByUser()->getType(),
				canSkipMandatory: $this->getOtpByUser()->canSkipMandatory(),
				canSkipMandatoryByRights: $this->getOtpByUser()->canSkipMandatoryByRights(),
				initParams: $this->getOtpByUser()->getInitParams(),
			);

			if ($cache->StartDataCache())
			{
				$cache->EndDataCache($this->otpInfo->toArray());
			}
		}
	}

	/**
	 * @throws ArgumentTypeException
	 */
	private function getOtpByUser(): Otp
	{
		if (!isset($this->securityOtp))
		{
			$this->securityOtp = Otp::getByUser($this->user->getId());
		}

		return $this->securityOtp;
	}
}

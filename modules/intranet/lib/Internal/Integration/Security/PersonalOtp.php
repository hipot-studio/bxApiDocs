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
use Bitrix\Intranet\Internal\Service\Otp\TrustDeviceConfirmation;
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
	private const CACHE_ID_PREFIX = 'user_otp_v5_';
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
		) {
			throw new OtpException('Permanent deactivation is not available');
		}

		$this->getOtpByUser()->deactivate($days);
		(new TrustDeviceConfirmation($this))->onDeactivateOtp();
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
		// delete it after adding the cache to security
		$result = $this->getOtpInfo()->isMandatorySkipped;

		if (!$result)
		{
			$result = $this->canSkipMandatoryByRights();
		}

		return $result;
	}

	public function canSkipMandatoryByRights(): bool
	{
		// delete it after adding the cache to security
		$targetRights = Otp::getMandatoryRights();
		$userRights = \CAccess::getUserCodesArray($this->otpInfo->userId);
		$existedRights = array_intersect($targetRights, $userRights);

		return empty($existedRights);
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
		$phoneConfirmed = false;

		if ($this->user->getAuthPhoneNumber())
		{
			$phoneAuthNumber = $this->user->getAuthPhoneNumber();
			$verifyPhone = new VerifyPhoneService($this->user);
			$phoneConfirmed = $verifyPhone->isConfirmed(new Phone($phoneAuthNumber));
		}
		else
		{
			$personalMobile = $this->user->getPersonalMobile();
			$phoneAuthNumber = $personalMobile ? (new Phone($this->user->getPersonalMobile()))->defaultFormat() : null;
		}

		return [
			'pullConfig' =>  $config['pullConfig'] ?? [],
			'ttl' => 600,
			'intent' => $intent,
			'phoneNumber' => $phoneAuthNumber,
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

	public function getInitialDate(): ?DateTime
	{
		return $this->otpInfo->initialDate;
	}

	public function isRequired(): bool
	{
		return !$this->canSkipMandatoryByRights();
	}

	public function getOtpInfo(): UserOtp
	{
		return $this->otpInfo;
	}

	public static function clearCache(int $userId): void
	{
		$cacheDir = self::BASE_CACHE_DIR . substr(md5((string)$userId), -2) . '/' . $userId . '/';
		Application::getInstance()->getCache()->cleanDir($cacheDir);
	}

	public function canSendRequestRecoverAccess(): bool
	{
		return !isset(Otp::getDeferredParams()['RECOVER_ACCESS_REQUEST_SENT']);
	}

	public function markRequestRecoverAccessSent(): void
	{
		$params = Otp::getDeferredParams();
		$params['RECOVER_ACCESS_REQUEST_SENT'] = true;
		Otp::setDeferredParams($params);
	}

	/**
	 * @throws ArgumentTypeException
	 */
	private function initOtpInfo(): void
	{
		$cacheId = self::CACHE_ID_PREFIX . $this->user->getId();
		$cacheDir = self::BASE_CACHE_DIR . substr(md5((string)$this->user->getId()), -2) . '/' . $this->user->getId() . '/';
		$cache = Application::getInstance()->getCache();

		if ($cache->initCache(86400 * 7, $cacheId, $cacheDir))
		{
			$otpData = $cache->getVars();
			$this->otpInfo = UserOtp::initByArray($otpData);
		}
		else
		{
			$this->otpInfo = new UserOtp(
				userId: $this->user->getId(),
				isActive: $this->getOtpByUser()->isActivated(),
				dateDeactivate: $this->getOtpByUser()->getDeactivateUntil(),
				isInitialized: $this->getOtpByUser()->isInitialized(),
				initialDate: $this->getOtpByUser()->getInitialDate(),
				type: $this->getOtpByUser()->getType(),
				isMandatorySkipped: $this->getOtpByUser()->isMandatorySkipped(),
				initParams: $this->getOtpByUser()->getInitParams(),
			);

			if ($cache->startDataCache())
			{
				$cache->endDataCache($this->otpInfo->toArray());
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

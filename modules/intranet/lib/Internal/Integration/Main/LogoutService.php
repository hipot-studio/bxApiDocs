<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Authentication\ApplicationPasswordTable;
use Bitrix\Main\Authentication\Context;
use Bitrix\Main\Authentication\Internal\UserStoredAuthTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\DeviceReconnect;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\UserAuthActionTable;
use Bitrix\Pull\Model\ChannelTable;
use Throwable;

class LogoutService
{
	public const ERROR_CURRENT_DEVICE_NOT_DETECTED = 'CURRENT_DEVICE_NOT_DETECTED';
	public const ERROR_LOGOUT_OTHER_DEVICES_FAILED = 'LOGOUT_OTHER_DEVICES_FAILED';

	private const MOBILE_APPLICATION_ID = 'mobile';

	public function __construct(
		private readonly User $user,
	) {
	}

	public function logoutAll(): void
	{
		$this->logoutAllWithResult();
	}

	public function logoutAllWithResult(): Result
	{
		$result = $this->addLogoutAction();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->setDeviceLostFlag();
		(new ApplicationPasswordService())->removeAllByUserId((int)$this->user->getId());
		$this->dropPullChannel();

		return $result;
	}

	public function logoutAllExceptTrustedDevice(): void
	{
		$this->logoutAllExceptTrustedDeviceWithResult();
	}

	public function logoutAllExceptTrustedDeviceWithResult(): Result
	{
		$result = $this->addLogoutAction();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$service = new ApplicationPasswordService();
		$deviceCode = $this->getTrustedDeviceCode();

		if ($deviceCode !== null)
		{
			$service->removeAllByUserIdExceptDevice((int)$this->user->getId(), $deviceCode);
		}
		else
		{
			$this->setDeviceLostFlag();
			$service->removeAllByUserId((int)$this->user->getId());
		}

		$this->dropPullChannel();

		return $result;
	}

	/**
	 * The logout auth action closes the HTTP plane only, while a pull channel is issued per user
	 * and keeps delivering until its own TTL expires. The row is read first because DeleteByUser()
	 * signs a null channel id when the user has no channel, which raises E_USER_WARNING.
	 */
	private function dropPullChannel(): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$userId = (int)$this->user->getId();
		$channel = ChannelTable::getRow([
			'filter' => [
				'=USER_ID' => $userId,
				'=CHANNEL_TYPE' => \CPullChannel::TYPE_PRIVATE,
			],
			'select' => ['CHANNEL_ID'],
		]);

		if ($channel === null)
		{
			return;
		}

		\CPullChannel::DeleteByUser($userId, $channel['CHANNEL_ID']);
	}

	public function logoutAllExceptCurrentMobileDevice(): Result
	{
		$result = new Result();
		$userId = (int)$this->user->getId();
		$currentMobilePasswordId = $this->getCurrentMobilePasswordId($userId);
		if ($currentMobilePasswordId === null)
		{
			return $result->addError(new Error(
				'Current mobile device is not detected.',
				self::ERROR_CURRENT_DEVICE_NOT_DETECTED,
			));
		}

		try
		{
			(new ApplicationPasswordService())->removeAllByUserIdExceptPasswordId($userId, $currentMobilePasswordId);
			$this->deleteOtherStoredAuth($userId, $this->getCurrentStoredAuthId());
			$this->addLogoutAction();
		}
		catch (Throwable)
		{
			return $result->addError(new Error(
				'Unable to logout other devices.',
				self::ERROR_LOGOUT_OTHER_DEVICES_FAILED,
			));
		}

		return $result;
	}

	private function addLogoutAction(): Result
	{
		if ($this->user->isCurrent())
		{
			global $USER;
			$USER->SetParam("AUTH_ACTION_SKIP_LOGOUT", true);
		}

		return UserAuthActionTable::addLogoutAction($this->user->getId());
	}

	private function getTrustedDeviceCode(): ?string
	{
		$personalOtp = (new OtpSettings())->getPersonalSettingsByUserId((int)$this->user->getId());
		if ($personalOtp === null)
		{
			return null;
		}

		$initParams = $personalOtp->getInitParams();

		return $initParams['deviceInfo']['id'] ?? null;
	}

	private function setDeviceLostFlag(): void
	{
		$personalOtp = (new OtpSettings())->getPersonalSettingsByUserId((int)$this->user->getId());
		if ($personalOtp?->hasConnectedPushDevice())
		{
			(new DeviceReconnect())->markLost();
		}
	}

	private function getCurrentMobilePasswordId(int $userId): ?int
	{
		$currentPasswordId = $this->getCurrentAuthContext()?->getApplicationPasswordId();
		if ($currentPasswordId !== null)
		{
			$password = ApplicationPasswordTable::query()
				->setSelect(['ID'])
				->where('ID', $currentPasswordId)
				->where('USER_ID', $userId)
				->where('APPLICATION_ID', self::MOBILE_APPLICATION_ID)
				->setLimit(1)
				->fetch()
			;

			if ($password !== false)
			{
				return (int)$password['ID'];
			}
		}

		return $this->getMobilePasswordIdFromBasicAuth($userId);
	}

	private function getMobilePasswordIdFromBasicAuth(int $userId): ?int
	{
		$auth = Main\Context::getCurrent()->getServer()->parseAuthRequest();
		if (!is_array($auth))
		{
			return null;
		}

		$password = $auth['basic']['password'] ?? null;
		if (!is_string($password) || $password === '')
		{
			return null;
		}

		$passwordData = ApplicationPasswordTable::findPassword($userId, $password);
		if ($passwordData === false || $passwordData['APPLICATION_ID'] !== self::MOBILE_APPLICATION_ID)
		{
			return null;
		}

		return (int)$passwordData['ID'];
	}

	private function getCurrentStoredAuthId(): ?int
	{
		$storedAuthId = $this->getCurrentAuthContext()?->getStoredAuthId();
		if ($storedAuthId === null || $storedAuthId <= 0)
		{
			return null;
		}

		return $storedAuthId;
	}

	private function getCurrentAuthContext(): ?Context
	{
		global $USER;

		if (!is_object($USER) || !method_exists($USER, 'getContext'))
		{
			return null;
		}

		return $USER->getContext();
	}

	private function deleteOtherStoredAuth(int $userId, ?int $currentStoredAuthId): void
	{
		$filter = [
			'=USER_ID' => $userId,
		];

		if ($currentStoredAuthId !== null)
		{
			$filter['!=ID'] = $currentStoredAuthId;
		}

		UserStoredAuthTable::deleteByFilter($filter);
	}
}

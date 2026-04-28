<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Main\Config\Option;
use Bitrix\Main\License;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class CustomServerAvailabilityProvider
{
	protected const OPTIONS_MAIN_EXPIRED_AT = '~custom_servers_expired_at';
	protected const OPTIONS_DISK_IS_ENABLED = 'custom_servers_enabled';

	protected ?bool $isAvailable = null;
	protected ?bool $isAvailableForEdit = null;
	protected ?bool $isAvailableForBuy = null;

	/**
	 * @param Environment $environment
	 * @param License $boxLicense
	 */
	public function __construct(
		protected readonly Environment $environment,
		protected readonly License $boxLicense,
	)
	{
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		if (!is_bool($this->isAvailable))
		{
			$this->isAvailable =
				$this->isValidPortalRegion()
				&& !$this->environment->isCloudPortal()
				&& $this->isEnabledByOption()
			;
		}

		return $this->isAvailable;
	}

	/**
	 * @return bool
	 */
	public function isAvailableForEdit(): bool
	{
		if (!is_bool($this->isAvailableForEdit))
		{
			$this->isAvailableForEdit =
				$this->isAvailable()
				&& $this->isValidLicense()
				&& !$this->isExpired()
			;
		}

		return $this->isAvailableForEdit;
	}

	/**
	 * @return bool
	 */
	public function isAvailableForUse(): bool
	{
		return $this->isAvailableForEdit();
	}

	/**
	 * @return bool
	 */
	public function isAvailableForBuy(): bool
	{
		if (!is_bool($this->isAvailableForBuy))
		{
			$this->isAvailableForBuy =
				$this->isAvailable()
				&& $this->isValidLicense()
				&& $this->isExpired()
			;
		}

		return $this->isAvailableForBuy;
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @return bool
	 */
	public function isAvailableCustomServerForView(CustomServerInterface $customServer): bool
	{
		return
			$this->isAvailableCustomServerInternal($customServer)
			&& $customServer->isConfigured();
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @return bool
	 */
	public function isAvailableCustomServerForUse(CustomServerInterface $customServer): bool
	{
		return
			$this->isAvailableCustomServerInternal($customServer)
			&& $customServer->isReadyForUse();
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @return bool
	 */
	public function isAvailableCustomServerForAdmin(CustomServerInterface $customServer): bool
	{
		return $this->isAvailableCustomServerInternal($customServer);
	}

	/**
	 * @return DateTime|null
	 */
	public function getExpiredAt(): ?DateTime
	{
		$expiredAt = Option::get('main', static::OPTIONS_MAIN_EXPIRED_AT);

		if (!is_string($expiredAt) || $expiredAt === '')
		{
			return null;
		}

		return DateTime::createFromTimestamp((int)$expiredAt);
	}

	/**
	 * @return bool
	 */
	protected function isValidPortalRegion(): bool
	{
		$regions = Configuration::getCustomServersRegions();

		if (!is_array($regions))
		{
			return false;
		}

		return in_array($this->boxLicense->getRegion(), $regions, true);
	}

	/**
	 * @return bool
	 */
	protected function isEnabledByOption(): bool
	{
		return Option::get('disk', static::OPTIONS_DISK_IS_ENABLED) === 'Y';
	}

	/**
	 * @return bool
	 */
	protected function isValidLicense(): bool
	{
		return
			in_array('Holding', $this->boxLicense->getCodes(), true)
			&& (
				!$this->boxLicense->isTimeBound()
				|| $this->boxLicense->getExpireDate() >= new Date()
			);
	}

	/**
	 * @return bool
	 */
	protected function isExpired(): bool
	{
		$expiredAt = $this->getExpiredAt();

		if (!$expiredAt instanceof DateTime)
		{
			return true;
		}

		return $expiredAt < new DateTime();
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @return bool
	 */
	protected function isAvailableCustomServerInternal(CustomServerInterface $customServer): bool
	{
		if (!$this->isAvailable() || !$customServer->isEnabled())
		{
			return false;
		}

		return $this->isAvailableForRegion($customServer);
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @return bool
	 */
	protected function isAvailableForRegion(CustomServerInterface $customServer): bool
	{
		$region = $this->boxLicense->getRegion();
		$availableRegions = $customServer->getAvailableRegions();

		if (is_array($availableRegions))
		{
			return in_array($region, $availableRegions, true);
		}

		$unavailableRegions = $customServer->getUnavailableRegions();

		if (is_array($unavailableRegions))
		{
			return !in_array($region, $unavailableRegions, true);
		}

		return true;
	}
}

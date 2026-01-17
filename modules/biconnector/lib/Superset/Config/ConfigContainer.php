<?php

namespace Bitrix\BIConnector\Superset\Config;

class ConfigContainer
{
	private static self $configContainer;

	private const PROXY_REGION_OPTION = '~superset_config_proxy_region';
	private const PORTAL_ID_OPTION = '~superset_config_portal_id';
	private const PORTAL_ID_VERIFIED = '~superset_config_portal_id_verified';
	private const CLEAR_CONFIG_REASON = '~superset_config_clear_config_reason';

	public static function getConfigContainer(): self
	{
		if (!isset(self::$configContainer))
		{
			self::$configContainer = new self();
		}

		return self::$configContainer;
	}

	public function setProxyRegion(string $region): void
	{
		\Bitrix\Main\Config\Option::set('biconnector', self::PROXY_REGION_OPTION, $region);
	}

	public function getProxyRegion(): string
	{
		return \Bitrix\Main\Config\Option::get('biconnector', self::PROXY_REGION_OPTION, '');
	}

	private function clearProxyRegion(): void
	{
		\Bitrix\Main\Config\Option::delete('biconnector', ['name' => self::PROXY_REGION_OPTION]);
	}

	public function getPortalId(): string
	{
		return \Bitrix\Main\Config\Option::get('biconnector', self::PORTAL_ID_OPTION, '');
	}

	public function setPortalId(string $clientId): void
	{
		$this->setPortalIdVerified(false);
		\Bitrix\Main\Config\Option::set('biconnector', self::PORTAL_ID_OPTION, $clientId);
	}

	private function clearPortalId(): void
	{
		$this->setPortalIdVerified(false);
		\Bitrix\Main\Config\Option::delete('biconnector', ['name' => self::PORTAL_ID_OPTION]);
	}

	public function setPortalIdVerified(bool $verify): void
	{
		\Bitrix\Main\Config\Option::set('biconnector', self::PORTAL_ID_VERIFIED, $verify ? 'Y' : 'N');
	}

	public function isPortalIdVerified(): bool
	{
		return \Bitrix\Main\Config\Option::get('biconnector', self::PORTAL_ID_VERIFIED, 'N') === 'Y';
	}

	public function clearConfig(string $reason = ''): void
	{
		$this->saveClearConfigReason($reason);

		$this->clearPortalId();
		$this->clearProxyRegion();
	}

	private function saveClearConfigReason(string $reason): void
	{
		$reasons = \Bitrix\Main\Config\Option::get('biconnector', self::CLEAR_CONFIG_REASON, '[]');
		try
		{
			$reasons = \Bitrix\Main\Web\Json::decode($reasons);
		}
		catch (\Exception)
		{
			$reasons = [];
		}

		if (!is_array($reasons))
		{
			$reasons = [];
		}

		$reasons[] = [
			'time' => time(),
			'reason' => $reason,
			'portalId' => $this->getPortalId(),
			'isVerified' => $this->isPortalIdVerified(),
		];

		\Bitrix\Main\Config\Option::set(
			'biconnector',
			self::CLEAR_CONFIG_REASON,
			\Bitrix\Main\Web\Json::encode($reasons)
		);
	}
}

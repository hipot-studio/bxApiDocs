<?php

namespace Bitrix\BIConnector\Superset\Config;

class ConfigContainer
{
	private static self $configContainer;

	private const PROXY_REGION_OPTION = '~superset_config_proxy_region';
	private const PORTAL_ID_OPTION = '~superset_config_portal_id';
	private const PORTAL_ID_VERIFIED = '~superset_config_portal_id_verified';
	private const SERVER_GENERATION_OPTION = '~superset_config_server_generation';
	private const LOCAL_DATA_GENERATION_OPTION = '~superset_config_local_data_generation';
	private const CLEAR_CONFIG_REASON = '~superset_config_clear_config_reason';

	private string $borrowedPortalId = '';
	private ?string $borrowedServerGeneration = null;
	private string $storedServerGenerationBeforeBorrow = '';

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

	/**
	 * Shows the registration of another server to this process for the time of a single call. Nothing is stored,
	 * so a killed process leaves nothing behind and other processes keep reading the stored registration.
	 * An empty portal id keeps the stored one visible. Borrows do not nest.
	 * Writes other than an adopted generation still reach the options and are seen once the borrow is over.
	 * The stored generation is remembered to tell later whether anyone has changed it during the call.
	 */
	public function beginRegistrationBorrow(string $portalId, string $serverGeneration): void
	{
		$this->storedServerGenerationBeforeBorrow = $this->readStoredServerGeneration();
		$this->borrowedPortalId = $portalId;
		$this->borrowedServerGeneration = $serverGeneration;
	}

	/**
	 * @param bool $keepServerGeneration Whether the generation of the borrow, including the one adopted during it,
	 *     is to be stored. It is stored only over an untouched value: a generation written meanwhile is newer.
	 */
	public function endRegistrationBorrow(bool $keepServerGeneration): void
	{
		$serverGeneration = (string)$this->borrowedServerGeneration;
		$this->borrowedPortalId = '';
		$this->borrowedServerGeneration = null;

		if ($keepServerGeneration && $this->readStoredServerGeneration() === $this->storedServerGenerationBeforeBorrow)
		{
			$this->replaceServerGeneration($serverGeneration);
		}
	}

	/**
	 * Option keeps the values loaded once per process, Option::getRealValue() included,
	 * so a write of another process is seen only in the table, and on the master of a cluster.
	 */
	private function readStoredServerGeneration(): string
	{
		$connectionPool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			$option = self::SERVER_GENERATION_OPTION;

			return (string)\Bitrix\Main\Application::getConnection()->queryScalar(
				"SELECT VALUE FROM b_option WHERE MODULE_ID = 'biconnector' AND NAME = '{$option}'"
			);
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}
	}

	public function isPortalIdBorrowed(): bool
	{
		return $this->borrowedPortalId !== '';
	}

	public function getPortalId(): string
	{
		if ($this->isPortalIdBorrowed())
		{
			return $this->borrowedPortalId;
		}

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
		if ($this->isPortalIdBorrowed())
		{
			return true;
		}

		return \Bitrix\Main\Config\Option::get('biconnector', self::PORTAL_ID_VERIFIED, 'N') === 'Y';
	}

	public function getServerGeneration(): string
	{
		return
			$this->borrowedServerGeneration
			?? \Bitrix\Main\Config\Option::get('biconnector', self::SERVER_GENERATION_OPTION, '')
		;
	}

	public function setServerGeneration(string $generation): void
	{
		if ($generation === '')
		{
			return;
		}

		if ($this->borrowedServerGeneration !== null)
		{
			$this->borrowedServerGeneration = $generation;
		}

		\Bitrix\Main\Config\Option::set('biconnector', self::SERVER_GENERATION_OPTION, $generation);
	}

	/**
	 * Takes the generation the service reported for an accepted operation of its own server.
	 * A known generation is never replaced this way.
	 */
	public function adoptServerGeneration(string $generation): bool
	{
		if ($generation === '' || $this->getServerGeneration() !== '')
		{
			return false;
		}

		if ($this->borrowedServerGeneration !== null)
		{
			$this->borrowedServerGeneration = $generation;

			return true;
		}

		\Bitrix\Main\Config\Option::set('biconnector', self::SERVER_GENERATION_OPTION, $generation);

		return true;
	}

	public function replaceServerGeneration(string $generation): void
	{
		if ($generation === '')
		{
			\Bitrix\Main\Config\Option::delete('biconnector', ['name' => self::SERVER_GENERATION_OPTION]);

			return;
		}

		\Bitrix\Main\Config\Option::set('biconnector', self::SERVER_GENERATION_OPTION, $generation);
	}

	public function isOutdatedServerGeneration(string $generation): bool
	{
		$currentGeneration = $this->getServerGeneration();

		return $generation !== '' && $currentGeneration !== '' && $generation !== $currentGeneration;
	}

	public function getLocalDataGeneration(): string
	{
		return \Bitrix\Main\Config\Option::get('biconnector', self::LOCAL_DATA_GENERATION_OPTION, '');
	}

	public function markLocalDataGeneration(string $generation): void
	{
		if ($generation === '' || $this->getLocalDataGeneration() !== '')
		{
			return;
		}

		\Bitrix\Main\Config\Option::set('biconnector', self::LOCAL_DATA_GENERATION_OPTION, $generation);
	}

	public function clearLocalDataGeneration(): void
	{
		\Bitrix\Main\Config\Option::delete('biconnector', ['name' => self::LOCAL_DATA_GENERATION_OPTION]);
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
			'serverGeneration' => $this->getServerGeneration(),
			'localDataGeneration' => $this->getLocalDataGeneration(),
		];

		$maxReasons = 100;
		if (count($reasons) > $maxReasons)
		{
			$reasons = array_slice($reasons, -$maxReasons);
		}

		\Bitrix\Main\Config\Option::set(
			'biconnector',
			self::CLEAR_CONFIG_REASON,
			\Bitrix\Main\Web\Json::encode($reasons)
		);
	}
}

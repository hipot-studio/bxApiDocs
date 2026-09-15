<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Integration\AnonymizerService;

use Bitrix\Anonymizer\Internal\Services\Config\ConfigSource;
use Bitrix\Anonymizer\Internal\Services\Portal\Region;
use Bitrix\Main\Config\Option;

/**
 * Proxy config. Server list: main Configuration (global .settings.php) first, then anonymizer module .settings.php.
 * Registration data (clientId, secretKey, serverHost) and use_proxy flag in Option.
 */
class ProxyConfig
{
	private const MODULE_ID = 'anonymizer';
	private const OPTION_USE_PROXY = 'use_proxy';
	private const OPTION_PROXY_CLIENT_ID = 'proxy_client_id';
	private const OPTION_PROXY_SECRET_KEY = 'proxy_secret_key';
	private const OPTION_PROXY_SERVER_HOST = 'proxy_server_host';
	private const OPTION_PROXY_DOMAIN_VERIFICATION_TEMP_SECRET = 'proxy_domain_verification_temp_secret';

	private ConfigSource $configSource;
	private Region $portalRegion;

	public function __construct(
		?ConfigSource $configSource = null,
		?Region $portalRegion = null,
	)
	{
		$this->configSource = $configSource ?? new ConfigSource();
		$this->portalRegion = $portalRegion ?? new Region();
	}

	public function isUseProxy(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_USE_PROXY, 'N') === 'Y';
	}

	public function setUseProxy(bool $use): void
	{
		Option::set(self::MODULE_ID, self::OPTION_USE_PROXY, $use ? 'Y' : 'N');
	}

	public function getProxyClientId(): ?string
	{
		return $this->getOptionString(self::OPTION_PROXY_CLIENT_ID);
	}

	public function getProxySecretKey(): ?string
	{
		return $this->getOptionString(self::OPTION_PROXY_SECRET_KEY);
	}

	public function getProxyServerHost(): ?string
	{
		return $this->getOptionString(self::OPTION_PROXY_SERVER_HOST);
	}

	private function getOptionString(string $optionName): ?string
	{
		$option = Option::get(self::MODULE_ID, $optionName, null);

		return $option !== null && $option !== '' ? $option : null;
	}

	public function setProxyRegistrationData(string $clientId, string $secretKey, string $serverHost): void
	{
		Option::set(self::MODULE_ID, self::OPTION_PROXY_CLIENT_ID, $clientId);
		Option::set(self::MODULE_ID, self::OPTION_PROXY_SECRET_KEY, $secretKey);
		Option::set(self::MODULE_ID, self::OPTION_PROXY_SERVER_HOST, $serverHost);
	}

	/**
	 * Clears stored proxy registration (Option) and disables use_proxy. Does not call the proxy.
	 */
	public function clearProxyRegistrationData(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_PROXY_CLIENT_ID, '');
		Option::set(self::MODULE_ID, self::OPTION_PROXY_SECRET_KEY, '');
		Option::set(self::MODULE_ID, self::OPTION_PROXY_SERVER_HOST, '');
		$this->resetTempSecretForDomainVerification();
		$this->setUseProxy(false);
	}

	public function isProxyRegistered(): bool
	{
		return
			$this->getProxyClientId() !== null
			&& $this->getProxySecretKey() !== null
			&& $this->getProxyServerHost() !== null
		;
	}

	/**
	 * @see \Bitrix\AnonymizerProxy\Infrastructure\Controllers\Registration::registerClientAction
	 */
	public function getTempSecretForDomainVerification(): ?string
	{
		return $this->getOptionString(self::OPTION_PROXY_DOMAIN_VERIFICATION_TEMP_SECRET);
	}

	public function resetTempSecretForDomainVerification(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_PROXY_DOMAIN_VERIFICATION_TEMP_SECRET, null);
	}

	public function storeTempSecretForDomainVerification(string $value): void
	{
		Option::set(self::MODULE_ID, self::OPTION_PROXY_DOMAIN_VERIFICATION_TEMP_SECRET, $value);
	}

	/**
	 * Returns the proxy server for current region.
	 *
	 * @return string|null
	 */
	public function getProxyServer(): ?string
	{
		$config = $this->configSource->get('proxy');
		if (!is_array($config) || !is_array($config['servers']))
		{
			return null;
		}

		$servers = $this->portalRegion->resolveListByRegion($config['servers']);
		if ($servers === null)
		{
			return null;
		}

		return is_array($servers) ? array_shift($servers) : (string)$servers;
	}
}

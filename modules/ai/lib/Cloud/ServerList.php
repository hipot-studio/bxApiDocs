<?php
declare(strict_types=1);

namespace Bitrix\AI\Cloud;

use Bitrix\AI\Config;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Class ServerList
 * Helps to get the list of allowed servers for the AI service.
 */
final class ServerList
{
	private const SOCKET_TIMEOUT = 5;
	private const STREAM_TIMEOUT = 5;

	public function __construct()
	{
	}

	/**
	 * Get the list of allowed servers: the `proxyServers` option if it is set, otherwise the list
	 * for the portal's region from the server list endpoint; empty if the endpoint is not configured.
	 * @return array Entries like ['proxy' => string, 'region' => ?string]; the `proxyServers` option is returned as is.
	 * @throws ArgumentException If the endpoint response is not valid JSON.
	 * @throws \RuntimeException If the server list endpoint is unavailable (no response or non-200 status),
	 * the decoded response is empty, or the server list in it is missing or empty.
	 */
	public function getAllowedServers(): array
	{
		$primarySettings = Config::getValue('proxyServers');
		if (!empty($primarySettings))
		{
			return $primarySettings;
		}

		$configuration = new Configuration();
		$serverListUrl = $configuration->getServerListEndpoint();
		$region = Application::getInstance()->getLicense()->getRegion();

		if (!$serverListUrl)
		{
			return [];
		}

		$http = new HttpClient([
			'socketTimeout' => self::SOCKET_TIMEOUT,
			'streamTimeout' => self::STREAM_TIMEOUT,
			'version' => HttpClient::HTTP_1_1,
		]);

		$responseRaw = $http->get($serverListUrl . '?' . http_build_query(['region' => $region]));

		if ($responseRaw === false)
		{
			throw new \RuntimeException('Server is not available.');
		}
		if ($http->getStatus() !== 200)
		{
			throw new \RuntimeException('Server is not available. Status ' . $http->getStatus());
		}

		$response = Json::decode($responseRaw);
		if (!$response)
		{
			throw new \RuntimeException('Could not decode response.');
		}

		if (empty($response['servers']))
		{
			throw new \RuntimeException('Empty server list.');
		}

		$servers = [];
		foreach ($response['servers'] as $server)
		{
			$servers[] = [
				'proxy' => $server['proxy'],
				'region' => $server['region'] ?? null,
			];
		}

		return $servers;
	}

	/**
	 * Check whether the service URL matches an allowed server entry.
	 * @param string $serviceUrl
	 * @param array|null $allowedServers entries like ['proxy' => string] or bare strings; null loads the live list
	 * @return bool
	 * @throws ArgumentException
	 * @throws \RuntimeException If the list is loaded and the server list endpoint fails.
	 */
	public function isAllowedServer(string $serviceUrl, ?array $allowedServers = null): bool
	{
		$allowedServers ??= $this->getAllowedServers();
		$serviceUrl = self::normalizeServiceUrl($serviceUrl);

		foreach ($allowedServers as $server)
		{
			$proxy = is_array($server) ? ($server['proxy'] ?? null) : $server;
			if (!is_string($proxy) || $proxy === '')
			{
				continue;
			}

			if (self::normalizeServiceUrl($proxy) === $serviceUrl)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a URL for strict comparison: trim, default to https scheme, lowercase, strip trailing slashes.
	 */
	private static function normalizeServiceUrl(string $url): string
	{
		$url = trim($url);
		if (!preg_match('~^https?://~i', $url))
		{
			$url = 'https://' . $url;
		}

		return rtrim(strtolower($url), '/');
	}

	/**
	 * Get the most suitable server based on the current region.
	 * @param array|null $allowedServers Preloaded server list; null loads it lazily.
	 * @return string|null
	 * @throws ArgumentException
	 * @throws \RuntimeException If the list is loaded and the server list endpoint fails.
	 */
	public function getMostSuitableServer(?array $allowedServers = null): ?string
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		$allowedServers ??= $this->getAllowedServers();

		if (empty($allowedServers))
		{
			return null;
		}

		foreach ($allowedServers as $server)
		{
			if (isset($server['region']) && $server['region'] === $region)
			{
				return $server['proxy'] ?? null;
			}
		}

		return $allowedServers[0]['proxy'] ?? null;
	}
}

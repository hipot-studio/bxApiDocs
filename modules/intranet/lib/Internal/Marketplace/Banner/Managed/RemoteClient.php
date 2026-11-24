<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Managed;

use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

final class RemoteClient
{
	private const ENDPOINT_PATH = '/widget2/banner_mp.php';
	private const SIGNATURE_PURPOSE = 'banner_mp';
	private const SCHEMA_VERSION = 1;
	private const SOCKET_TIMEOUT = 2;
	private const STREAM_TIMEOUT = 5;
	private const BODY_LENGTH_MAX = 65536;

	private readonly RemoteRequestContext $requestContext;

	public function __construct(?RemoteRequestContext $requestContext = null)
	{
		$this->requestContext = $requestContext ?? new RemoteRequestContext();
	}

	/**
	 * @return array{status: RemoteStatus, campaign: Campaign|null}
	 */
	public function fetch(): array
	{
		try
		{
			$helpdeskUrl = $this->requestContext->getHelpdeskUrl();
			$endpoint = $this->buildEndpoint($helpdeskUrl);
			$httpClient = $this->requestContext->createHttpClient($this->getHttpClientOptions());
			$responseBody = $httpClient->post($endpoint, $this->buildRequestFields());
		}
		catch (\Throwable)
		{
			return $this->errorResult();
		}

		if ($responseBody === false || (int)$httpClient->getStatus() !== 200)
		{
			return $this->errorResult();
		}

		if (strlen((string)$responseBody) > self::BODY_LENGTH_MAX)
		{
			return $this->errorResult();
		}

		try
		{
			$response = Json::decode((string)$responseBody);
		}
		catch (\Throwable)
		{
			return $this->errorResult();
		}

		if (!is_array($response) || !array_key_exists('banner', $response))
		{
			return $this->errorResult();
		}

		if ($response['banner'] === null)
		{
			return $this->emptyResult();
		}

		if (!is_array($response['banner']))
		{
			return $this->errorResult();
		}

		$campaign = Campaign::fromArray($response['banner']);
		if ($campaign === null || !$this->hasValidButtonUrls($campaign, $helpdeskUrl))
		{
			return $this->errorResult();
		}

		return [
			'status' => RemoteStatus::Campaign,
			'campaign' => $campaign,
		];
	}

	/**
	 * @return array{
	 *     socketTimeout: int,
	 *     streamTimeout: int,
	 *     bodyLengthMax: int,
	 *     privateIp: bool,
	 *     redirect: bool
	 * }
	 */
	private function getHttpClientOptions(): array
	{
		return [
			'socketTimeout' => self::SOCKET_TIMEOUT,
			'streamTimeout' => self::STREAM_TIMEOUT,
			'bodyLengthMax' => self::BODY_LENGTH_MAX,
			'privateIp' => false,
			'redirect' => false,
		];
	}

	private function buildEndpoint(string $helpdeskUrl): string
	{
		return rtrim($helpdeskUrl, '/') . self::ENDPOINT_PATH;
	}

	private function hasValidButtonUrls(Campaign $campaign, string $helpdeskUrl): bool
	{
		$helpdeskUri = new Uri($helpdeskUrl);
		$helpdeskScheme = mb_strtolower($helpdeskUri->getScheme());
		$helpdeskHost = mb_strtolower($helpdeskUri->getHost());
		if (
			$helpdeskScheme !== 'https'
			|| $helpdeskHost === ''
			|| $helpdeskUri->getPort() !== 443
			|| $helpdeskUri->getUserInfo() !== ''
		)
		{
			return false;
		}

		foreach ($campaign->localizations as $localization)
		{
			$buttonUrl = is_array($localization) ? ($localization['buttonUrl'] ?? null) : null;
			if (!is_string($buttonUrl) || $this->hasUnsafeUrlCharacters($buttonUrl))
			{
				return false;
			}

			if (str_starts_with($buttonUrl, '/') && !str_starts_with($buttonUrl, '//'))
			{
				continue;
			}

			if (preg_match('~^https://[^/?#]*@~i', $buttonUrl) === 1)
			{
				return false;
			}

			$buttonUri = new Uri($buttonUrl);
			if (
				mb_strtolower($buttonUri->getScheme()) !== $helpdeskScheme
				|| mb_strtolower($buttonUri->getHost()) !== $helpdeskHost
				|| $buttonUri->getPort() !== 443
				|| $buttonUri->getUserInfo() !== ''
			)
			{
				return false;
			}
		}

		return true;
	}

	private function hasUnsafeUrlCharacters(string $url): bool
	{
		return preg_match('~[\\x00-\\x20\\\\]~', $url) === 1;
	}

	/**
	 * @return array{host: string, schemaVersion: int, timestamp: int, key: string}
	 */
	private function buildRequestFields(): array
	{
		$host = mb_strtolower(rtrim(trim($this->requestContext->getHost()), '.'));
		$timestamp = $this->requestContext->getTimestamp();
		$payload = implode('|', [
			self::SIGNATURE_PURPOSE,
			$host,
			(string)self::SCHEMA_VERSION,
			(string)$timestamp,
		]);

		return [
			'host' => $host,
			'schemaVersion' => self::SCHEMA_VERSION,
			'timestamp' => $timestamp,
			'key' => $this->requestContext->sign($payload),
		];
	}

	/**
	 * @return array{status: RemoteStatus, campaign: null}
	 */
	private function emptyResult(): array
	{
		return [
			'status' => RemoteStatus::Empty,
			'campaign' => null,
		];
	}

	/**
	 * @return array{status: RemoteStatus, campaign: null}
	 */
	private function errorResult(): array
	{
		return [
			'status' => RemoteStatus::Error,
			'campaign' => null,
		];
	}
}

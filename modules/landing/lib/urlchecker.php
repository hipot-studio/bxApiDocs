<?php
namespace Bitrix\Landing;

use Bitrix\Landing\Internals\UrlCheckerStatusTable;
use Bitrix\Landing\Internals\UrlCheckerWhitelistTable;
use Bitrix\Landing\Internals\UrlCheckerHostTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Class for check urls at external virus tools
 */
class UrlChecker
{
	private const EXTERNAL_CHECKER_URL_LOAD_URL = 'https://www.virustotal.com/api/v3/urls';
	private const EXTERNAL_CHECKER_URL_GET_ANALYSE = 'https://www.virustotal.com/api/v3/analyses/';

	private const STATUS_KEY_GOOD = 'harmless';
	private const STATUS_KEY_AVERAGE = 'suspicious';
	private const STATUS_KEY_BAD = 'malicious';

	private const AVERAGE_STATUS_PERCENT = 33;

	private $apiKey;
	private $hostUrl;

	/**
	 * UrlChecker constructor.
	 *
	 * @param string $apiKey Google Service api key.
	 * @param string|null $hostUrl External URL, where was checking and bad URL was detected.
	 */
	public function __construct(string $apiKey, ?string $hostUrl = null)
	{
		$this->apiKey = $apiKey;
		$this->hostUrl = $hostUrl;
		if ($this->hostUrl)
		{
			$this->hostUrl = mb_strtolower(trim($this->hostUrl));
		}
	}

	/**
	 * Checks passed url for some threats.
	 * Returns threat's status or null on no threats.
	 *
	 * @param string $url
	 * @return string|null
	 * @throws SystemException If the external service could not check the url.
	 */
	protected function getUrlStatus(string $url): ?string
	{
		$http = $this->createHttpClient();
		$http->setHeader('x-apikey', $this->apiKey);

		$res = $this->decodeResponse($http->post(self::EXTERNAL_CHECKER_URL_LOAD_URL, [
			'url' => $url,
		]));
		$analysisId = $res['data']['id'] ?? null;
		if (isset($res['error']) || !$analysisId)
		{
			throw new SystemException('Url checker could not submit the url for analysis.');
		}

		$resAnalysis = $this->decodeResponse($http->get(self::EXTERNAL_CHECKER_URL_GET_ANALYSE . $analysisId));
		$analysisStats = $resAnalysis['data']['attributes']['stats'] ?? null;
		if (isset($resAnalysis['error']) || !is_array($analysisStats))
		{
			throw new SystemException('Url checker could not get the analysis of the url.');
		}
		if (($resAnalysis['data']['attributes']['status'] ?? null) !== 'completed')
		{
			throw new SystemException('Url checker got an unfinished analysis of the url.');
		}

		$stats = [
			self::STATUS_KEY_GOOD => (int)($analysisStats[self::STATUS_KEY_GOOD] ?? 0),
			self::STATUS_KEY_AVERAGE => (int)($analysisStats[self::STATUS_KEY_AVERAGE] ?? 0),
			self::STATUS_KEY_BAD => (int)($analysisStats[self::STATUS_KEY_BAD] ?? 0),
		];

		// check stats
		$total = $stats[self::STATUS_KEY_GOOD] + $stats[self::STATUS_KEY_AVERAGE] + $stats[self::STATUS_KEY_BAD];
		if (
			$stats[self::STATUS_KEY_BAD] === 0
			&& $stats[self::STATUS_KEY_AVERAGE] <= $total * self::AVERAGE_STATUS_PERCENT * 0.01
		)
		{
			return null;
		}

		return
			self::STATUS_KEY_GOOD . ':' . $stats[self::STATUS_KEY_GOOD]
			. '_' . self::STATUS_KEY_AVERAGE . ':' . $stats[self::STATUS_KEY_AVERAGE]
			. '_' . self::STATUS_KEY_BAD . ':' . $stats[self::STATUS_KEY_BAD];
	}

	protected function createHttpClient(): HttpClient
	{
		return new HttpClient();
	}

	/**
	 * @throws SystemException
	 */
	private function decodeResponse(string|false $response): array
	{
		if ($response === false || $response === '')
		{
			throw new SystemException('Url checker got no response from the external service.');
		}

		try
		{
			$decoded = Json::decode($response);
		}
		catch (ArgumentException $e)
		{
			throw new SystemException('Url checker got a malformed response from the external service.', 0, '', 0, $e);
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Saves host for specified status.
	 *
	 * @param int $statusId Status id.
	 * @return void
	 */
	protected function saveHost(int $statusId): void
	{
		if (!$this->hostUrl)
		{
			return;
		}
		$res = UrlCheckerHostTable::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'STATUS_ID' => $statusId,
				'=HOST' => $this->hostUrl
			],
			'limit' => 1
		]);
		if (!$res->fetch())
		{
			UrlCheckerHostTable::add([
				'STATUS_ID' => $statusId,
				'HOST' => $this->hostUrl
			])->isSuccess();
		}
	}

	/**
	 * Checks passed url for some threats. Returns true on no threats.
	 * Before external checking check whitelist list and stored links.
	 * If the external service fails, the url is not stored and is checked again next time.
	 *
	 * @param string $url
	 * @return bool
	 */
	public function check(string $url): bool
	{
		$url = mb_strtolower($url);
		$urlMd5 = md5(explode('//', $url)[1] ?? $url);
		$urlParts = parse_url($url);
		$domain = $urlParts['host'] ?? null;

		if (!$domain)
		{
			return true;
		}

		// check domain in white list
		$res = UrlCheckerWhitelistTable::getList([
			'filter' => [
				'=DOMAIN' => $domain
			],
			'limit' => 1
		]);
		if ($res->fetch())
		{
			return true;
		}

		// check exists url
		$res = UrlCheckerStatusTable::getList([
			'select' => [
				'ID', 'STATUS'
			],
			'filter' => [
				'=HASH' => $urlMd5
			]
		]);
		if ($row = $res->fetch())
		{
			if ($row['STATUS'])
			{
				$this->saveHost($row['ID']);
			}
			return !$row['STATUS'];
		}

		// new check
		try
		{
			$status = $this->getUrlStatus($url);
		}
		catch (\Exception $e)
		{
			return true;
		}

		$res = UrlCheckerStatusTable::add([
			'STATUS' => $status,
			'HASH' => $urlMd5,
			'URL' => $url
		]);
		// save host if status is bad
		if ($res->isSuccess() && $status)
		{
			$this->saveHost($res->getId());
		}

		return $status === null;
	}
}

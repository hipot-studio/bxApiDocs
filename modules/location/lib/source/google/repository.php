<?php

namespace Bitrix\Location\Source\Google;

use Bitrix\Location\Entity\Location;
use Bitrix\Location\Entity\Generic\Collection;
use Bitrix\Location\Exception\RuntimeException;
use Bitrix\Location\Repository\Location\Capability\IFindByCoords;
use Bitrix\Location\Repository\Location\Capability\IFindByExternalId;
use Bitrix\Location\Repository\Location\Capability\IFindByText;
use Bitrix\Location\Repository\Location\IRepository;
use Bitrix\Location\Repository\Location\ISource;
use Bitrix\Location\Source\BaseRepository;
use Bitrix\Location\Source\Google\Converters;
use Bitrix\Location\Source\Google\Converters\BaseConverter;
use Bitrix\Location\Source\Google\Requesters;
use Bitrix\Location\Source\Google\Requesters\BaseRequester;
use \Bitrix\Location\Common\CachedPool;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;

Loc::loadMessages(__FILE__);

/**
 * Class Google
 * @package Bitrix\Location\Source
 */
class Repository extends BaseRepository implements
	IRepository,
	IFindByExternalId,
	IFindByCoords,
	IFindByText,
	ISource
{
	/** @var string  */
	protected $apiKey = '';
	/** @var string  */
	protected static $sourceCode = 'GOOGLE';
	/** @var HttpClient  */
	protected $httpClient = null;
	/** @var CachedPool */
	protected $cachePool = null;
	/** @var GoogleSource  */
	protected $googleSource = null;

	public function __construct(
		string $apiKey,
		HttpClient $httpClient,
		GoogleSource $googleSource,
		CachedPool $cachePool = null
	)
	{
		$this->apiKey = $apiKey;
		$this->httpClient = $httpClient;
		$this->cachePool = $cachePool;
		$this->googleSource = $googleSource;
	}

	/** @inheritDoc */
	public function findByExternalId(string $locationExternalId, string $sourceCode, string $languageId)
	{
		if ($sourceCode !== self::$sourceCode || $locationExternalId === '')
		{
			return null;
		}

		return $this->find(
			new Requesters\ByIdRequester($this->httpClient, $this->cachePool),
			new Converters\ByIdConverter($languageId),
			[
				'placeid' => $locationExternalId,
				'language' => $this->googleSource->convertLang($languageId),
			]
		);
	}

	public function findByCoords(float $lat, float $lng, int $zoom, string $languageId): ?Location
	{
		$foundLocations = $this->find(
			new Requesters\ByCoordsRequester($this->httpClient, $this->cachePool),
			new Converters\ByCoordsConverter($languageId),
			[
				'latlng' => implode(',', [$lat, $lng]),
				'language' => $this->googleSource->convertLang($languageId),
			]
		);

		return $foundLocations[0] ?? null;
	}

	/**
	 * @inheritDoc
	 */
	public function findByText(string $query, string $languageId)
	{
		if ($query == '')
		{
			return null;
		}

		return $this->find(
			new Requesters\ByQueryRequester($this->httpClient, $this->cachePool),
			new Converters\ByQueryConverter($languageId),
			[
				'query' => $query,
				'language' => $this->googleSource->convertLang($languageId)
			]
		);
	}

	/**
	 * @param Requesters\BaseRequester $requester
	 * @param Converters\BaseConverter $converter
	 * @return Finder
	 */
	protected function buildFinder($requester, $converter)
	{
		return new Finder($requester, $converter);
	}

	/**
	 * @param BaseRequester $requester`
	 * @param BaseConverter $converter
	 * @param array $findParams
	 * @return Location|Collection|false|null|array
	 */
	protected function find($requester,  $converter = null, array $findParams = [])
	{
		if ($this->apiKey === '')
		{
			throw new RuntimeException(
				Loc::getMessage('LOCATION_ADDRESS_REPOSITORY_API_KEY_ERROR'),
				ErrorCodes::REPOSITORY_FIND_API_KEY_ERROR
			);
		}

		$finder = $this->buildFinder($requester, $converter);
		$findParams['key'] = $this->apiKey;

		return $finder->find($findParams);
	}

	/** @inheritDoc */
	public static function getSourceCode(): string
	{
		return self::$sourceCode;
	}
}

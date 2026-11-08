<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Location\Entity\Address;
use Bitrix\Location\Entity\Address\Converter\StringConverter;
use Bitrix\Location\Entity\Format;
use Bitrix\Location\Entity\Format\Template;
use Bitrix\Location\Entity\Format\TemplateCollection;
use Bitrix\Location\Entity\Format\TemplateType;
use Bitrix\Location\Entity\Location;
use Bitrix\Location\Entity\Source;
use Bitrix\Location\Entity\Source\Factory as SourceFactory;
use Bitrix\Location\Entity\Source\OrmConverter;
use Bitrix\Location\Repository\SourceRepository;
use Bitrix\Location\Service\FormatService;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Service\Snapshot\SnapshotFileIdCache;
use Bitrix\StaffTrack\Internal\Service\Snapshot\StaticMapSnapshotService;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\SnapshotCache;

class GeoProvider
{
	private float $latitude;
	private float $longitude;

	private const ADDRESS_ZOOM = 18;
	private const SNAPSHOT_SUBDIR = 'stafftrack/static_map';

	private const DEFAULT_TEMPLATE = '[", ",[ADDRESS_LINE_1,LOCALITY]]';
	private const DEFAULT_ADDRESS_LINE_TEMPLATE = '[" ",[STREET,BUILDING]]';
	private const SHORT_TEMPLATE = '[", ",[LOCALITY,ADM_LEVEL_1,COUNTRY]]';

	public function __construct(
		float $latitude,
		float $longitude,
	)
	{
		$this->latitude = $latitude;
		$this->longitude = $longitude;
	}

	/**
	 * @return Result
	 * @throws LoaderException
	 */
	public function generateSnapshot(): Result
	{
		$result = new Result();

		$snapshots = $this->getSnapshotService();

		$sourceResult = $snapshots->resolveSource();
		if (!$sourceResult->isSuccess())
		{
			return $result->addErrors($sourceResult->getErrors());
		}

		/** @var Source $source */
		$source = $sourceResult->getData()['source'];

		$latitude = StaticMapSnapshotService::roundCoordinate($this->latitude);
		$longitude = StaticMapSnapshotService::roundCoordinate($this->longitude);
		$hash = self::buildSnapshotHash($source->getCode(), $latitude, $longitude);

		$cachedPath = $this->resolveCachedSnapshot($hash);
		if ($cachedPath !== null)
		{
			return $result->setData(['snapshotUrl' => $cachedPath]);
		}

		$captured = $snapshots->capture($source, $latitude, $longitude);
		if (!$captured->isSuccess())
		{
			return $result->addErrors($captured->getErrors());
		}

		$fileId = $snapshots->save($captured->getData()['snapshot'], $hash, self::SNAPSHOT_SUBDIR);
		if ($fileId === null)
		{
			return $result->addError(new Error('Failed to save static map image'));
		}

		$this->getSnapshotCache()->setFileId($hash, $fileId);

		$filePath = $snapshots->getFilePath($fileId);
		if ($filePath === null)
		{
			return $result->addError(new Error('Failed to resolve static map image path'));
		}

		return $result->setData(['snapshotUrl' => $filePath]);
	}

	protected function getSnapshotService(): StaticMapSnapshotService
	{
		return new StaticMapSnapshotService();
	}

	protected function getSnapshotCache(): SnapshotFileIdCache
	{
		return new SnapshotFileIdCache(SnapshotCache::CACHE_DIR, SnapshotCache::CACHE_ID_PREFIX);
	}

	private function resolveCachedSnapshot(string $hash): ?string
	{
		$fileId = $this->getSnapshotCache()->getFileId($hash);
		if ($fileId === null)
		{
			return null;
		}

		return $this->getSnapshotService()->getFilePath($fileId);
	}

	private static function buildSnapshotHash(string $sourceCode, float $latitude, float $longitude): string
	{
		return sha1(implode(';', [
			$sourceCode,
			$longitude,
			$latitude,
			StaticMapSnapshotService::IMAGE_ZOOM,
			StaticMapSnapshotService::IMAGE_WIDTH,
			StaticMapSnapshotService::IMAGE_HEIGHT,
		]));
	}

	public function getAddress(): Result
	{
		if (!Feature::isGeoEncryptionAvailable())
		{
			return (new Result())->setData(['address' => '']);
		}

		$geohash = Geohash::encode($this->latitude, $this->longitude);
		$cached = AddressCache::get($geohash);
		if ($cached !== null)
		{
			return (new Result())->setData(['address' => $cached]);
		}

		$result = $this->generateAddress();
		$address = $result->isSuccess() ? ($result->getData()['address'] ?? '') : '';

		if ($address !== '')
		{
			AddressCache::set($geohash, $address);
		}

		return $result;
	}

	/**
	 * @return Result
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException|LoaderException
	 */
	public function generateAddress(
		string $template = self::DEFAULT_TEMPLATE,
		string $addressLineTemplate = self::DEFAULT_ADDRESS_LINE_TEMPLATE,
	): Result
	{
		$result = new Result();

		if (!$this->isLocationModuleLoaded())
		{
			return $result->addError(new Error('Location module is not available'));
		}

		$location = $this->findLocation();
		if (!$location || !$location->getAddress())
		{
			return $result->addError(new Error('Location not found'));
		}

		$address = $location->getAddress();

		if ($address === null)
		{
			return $result->addError(new Error('Address not found'));
		}

		if ($this->hasDetailedAddress($address))
		{
			$format = $this->buildAddressFormat($template, $addressLineTemplate);
		}
		else
		{
			$format = $this->buildAddressFormat(self::SHORT_TEMPLATE, $addressLineTemplate);
		}

		$addressString = $this->convertAddressToString($address, $format);

		$result->setData([
			'address' => $addressString,
			'externalId' => $location->getExternalId(),
			'sourceCode' => $location->getSourceCode(),
		]);

		return $result;
	}

	private function findLocation(): ?Location
	{
		$language = $this->getLanguage();

		$source = $this->getSource();
		if (!$source)
		{
			return null;
		}

		return $source->makeRepository()?->findByCoords(
			$this->latitude,
			$this->longitude,
			self::ADDRESS_ZOOM,
			$language,
		);
	}

	/**
	 * @return Format
	 */
	private function buildAddressFormat(string $template, string $addressLineTemplate): Format
	{
		$language = $this->getLanguage();

		$format = FormatService::getInstance()->findDefault($language);
		$format->setTemplateCollection(new TemplateCollection([
			new Template(TemplateType::DEFAULT, $template),
			new Template(TemplateType::ADDRESS_LINE_1, $addressLineTemplate),
		]));

		return $format;
	}

	private function hasDetailedAddress(Address $address): bool
	{
		return
			$address->getFieldValue(Location\Type::STREET) !== null
			|| $address->getFieldValue(Location\Type::BUILDING) !== null
		;
	}

	private function convertAddressToString(Address $address, Format $format): string
	{
		return StringConverter::convertToString(
			$address,
			$format,
			StringConverter::STRATEGY_TYPE_TEMPLATE_COMMA,
			StringConverter::CONTENT_TYPE_TEXT,
		);
	}

	private function getLanguage(): string
	{
		return Context::getCurrent()?->getLanguage() ?? LANGUAGE_ID;
	}

	private function getSource(): ?Source
	{
		// TODO: support multiple source providers, not only OSM
		static $source;

		if ($source !== null)
		{
			return $source ?: null;
		}

		$source = (new SourceRepository(new OrmConverter()))
			->findByCode(SourceFactory::OSM_SOURCE_CODE)
		;

		if (!$source || !$source->isAvailable())
		{
			$source = false;

			return null;
		}

		return $source;
	}

	/**
	 * @throws LoaderException
	 */
	private function isLocationModuleLoaded(): bool
	{
		return Loader::includeModule('location');
	}
}

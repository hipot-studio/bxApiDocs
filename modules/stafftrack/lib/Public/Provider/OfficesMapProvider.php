<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Location\Entity\Source;
use Bitrix\Location\Infrastructure\UserLocation;
use Bitrix\Location\Repository\SourceRepository;
use Bitrix\Location\Source\Osm\OsmSource;
use Bitrix\Location\Source\Osm\Token;
use Bitrix\Main\Loader;

class OfficesMapProvider
{
	private ?Source $source = null;
	private bool $isSourceRequested = false;

	public function isMapAvailable(): bool
	{
		return $this->getOsmToken() !== null;
	}

	public function getMapParams(): array
	{
		$source = $this->getSource();
		if ($source === null)
		{
			return [];
		}

		$mapCenter = UserLocation::getPoint();

		return [
			'sourceCode' => $source->getCode(),
			'sourceParams' => $source->getJSParams(),
			'languageId' => LANGUAGE_ID,
			'sourceLanguageId' => $source->convertLang(LANGUAGE_ID),
			'mapCenter' => [
				'latitude' => $mapCenter->getLat(),
				'longitude' => $mapCenter->getLng(),
			],
		];
	}

	protected function getOsmToken(): ?Token
	{
		$source = $this->getSource();

		return $source instanceof OsmSource ? $source->getOsmToken() : null;
	}

	protected function getSource(): ?Source
	{
		if ($this->isSourceRequested)
		{
			return $this->source;
		}

		$this->isSourceRequested = true;

		if (!Loader::includeModule('location'))
		{
			return null;
		}

		$this->source = (new SourceRepository(new Source\OrmConverter()))
			->findByCode(Source\Factory::OSM_SOURCE_CODE)
		;

		return $this->source;
	}
}

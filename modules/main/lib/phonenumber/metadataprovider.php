<?php

namespace Bitrix\Main\PhoneNumber;

use Bitrix\Main\IO\File;
use Bitrix\Main\SystemException;

class MetadataProvider
{
	const PARSED_METADATA_FILENAME = 'metadata.php';

	protected $metadata;
	protected $codeToCountries;
	protected static $instance;

	protected function __construct()
	{
		$this->loadMetadata();
	}

	/**
	 * Returns instance of MetadataProvider.
	 * @return MetadataProvider
	 */
	public static function getInstance()
	{
		if (is_null(static::$instance))
		{
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Returns array of 2-letter country codes of the countries, sharing the specified phone country code.
	 * @param string $countryCode Phone country code.
	 * @return array
	 */
	public function getCountriesByCode($countryCode)
	{
		return is_array($this->codeToCountries[$countryCode]) ? $this->codeToCountries[$countryCode] : [];
	}

	public function isValidCountryCode($countryCode)
	{
		return isset($this->codeToCountries[$countryCode]);
	}

	/**
	 * Returns metadata record for the country.
	 * @param string $country 2-letter country code.
	 * @return array|false
	 */
	public function getCountryMetadata($country)
	{
		$country = mb_strtoupper($country);
		return $this->metadata[$country] ?? false;
	}

	public function toArray()
	{
		return [
			'codeToCountries' => $this->codeToCountries,
			'metadata' => $this->metadata,
		];
	}

	/**
	 * Parses google metadata from the PhoneNumberMetadata.xml
	 * @see https://github.com/googlei18n/libphonenumber/blob/master/resources/
	 * @params string $fileName Metadata file.
	 * @return array Returns parsed metadata.
	 */
	public static function parseGoogleMetadata($fileName)
	{
		$metadataBuilder = new \Bitrix\Main\PhoneNumber\Tools\MetadataBuilder($fileName);

		$metadata = $metadataBuilder->build();
		$codeToCountries = [];

		foreach ($metadata as $metadataRecord)
		{
			$country = mb_strtoupper($metadataRecord['id']);
			if (!is_array($codeToCountries[$metadataRecord['countryCode']]))
			{
				$codeToCountries[$metadataRecord['countryCode']] = [];
			}

			if ($metadataRecord['mainCountryForCode'])
			{
				array_unshift($codeToCountries[$metadataRecord['countryCode']], $country);
			}
			else
			{
				$codeToCountries[$metadataRecord['countryCode']][] = $country;
			}
		}

		return [
			'codeToCountries' => $codeToCountries,
			'metadata' => $metadata,
		];
	}

	/**
	 * Loads parsed metadata.
	 * @return void
	 * @throws SystemException
	 */
	protected function loadMetadata()
	{
		$dataFile = __DIR__ . '/' . static::PARSED_METADATA_FILENAME;

		if (!File::isFileExists($dataFile))
		{
			throw new SystemException("Metadata file is not found");
		}

		$parsedMetadata = include($dataFile);

		$this->codeToCountries = $parsedMetadata['codeToCountries'];
		foreach ($parsedMetadata['metadata'] as $metadataRecord)
		{
			$this->metadata[$metadataRecord['id']] = $metadataRecord;
		}
	}
}

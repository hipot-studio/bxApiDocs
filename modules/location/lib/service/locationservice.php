<?php

namespace Bitrix\Location\Service;

use Bitrix\Location\Exception\RuntimeException;
use Bitrix\Location\Common\BaseService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Location\Entity;
use Bitrix\Location\Repository\LocationRepository;
use Bitrix\Location\Infrastructure\Service\Config;
use Bitrix\Location\Common\RepositoryTrait;

/**
 * Class LocationService
 *
 * Service to work with locations
 *
 * @package Bitrix\Location\Service
 */
final class LocationService extends BaseService
{
	use RepositoryTrait;

	/** Maximum number of coordinates allowed in a single findByCoordsList batch. */
	public const MAX_BATCH_SIZE = 20;

	/** @var LocationService */
	protected static $instance;

	/** @var LocationRepository  */
	protected $repository = null;

	/**
	 * Find Location by locationId
	 *
	 * @param int $locationId
	 * @param string $languageId
	 * @param int $searchScope
	 * @return Entity\Location|null|bool
	 */
	public function findById(int $locationId, string $languageId, int $searchScope = LOCATION_SEARCH_SCOPE_ALL)
	{
		$result = false;

		try
		{
			$result = $this->repository->findById($locationId, $languageId, $searchScope);
		}
		catch (RuntimeException $exception)
		{
			$this->processException($exception);
		}

		return $result;
	}

	/**
	 * Find location by externalId
	 *
	 * @param string $externalId
	 * @param string $sourceCode
	 * @param string $languageId
	 * @param int $searchScope
	 * @return Entity\Location|bool|null
	 */
	public function findByExternalId(
		string $externalId,
		string $sourceCode,
		string $languageId,
		int $searchScope = LOCATION_SEARCH_SCOPE_ALL
	)
	{
		$result = false;

		try
		{
			$result = $this->repository->findByExternalId($externalId, $sourceCode, $languageId, $searchScope);
		}
		catch (RuntimeException $exception)
		{
			$this->processException($exception);
		}

		return $result;
	}

	/**
	 * Find location by coordinates
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param int $zoom
	 * @param string $languageId
	 * @return Entity\Location|null
	 */
	public function findByCoords(
		float $lat,
		float $lng,
		int $zoom,
		string $languageId
	): ?Entity\Location
	{
		try
		{
			return $this->repository->findByCoords(
				$lat,
				$lng,
				$zoom,
				$languageId,
				LOCATION_SEARCH_SCOPE_EXTERNAL
			);
		}
		catch (RuntimeException $exception)
		{
			$this->processException($exception);
		}

		return null;
	}

	/**
	 * Find locations by a list of coordinates (batch reverse geocoding).
	 *
	 * Result is aligned by input index; not found coordinates yield null in their position.
	 *
	 * @param array $coordsList Index-aligned list of ['lat' => float, 'lng' => float].
	 * @param int $zoom Shared zoom for the whole batch.
	 * @param string $languageId Shared language for the whole batch.
	 * @return array<int, Entity\Location|null>
	 * @throws ArgumentException If the batch size exceeds self::MAX_BATCH_SIZE or an element lacks numeric lat/lng.
	 */
	public function findByCoordsList(array $coordsList, int $zoom, string $languageId): array
	{
		if (!$coordsList)
		{
			return [];
		}

		if (count($coordsList) > self::MAX_BATCH_SIZE)
		{
			throw new ArgumentException(
				'Batch size exceeds the maximum of ' . self::MAX_BATCH_SIZE,
				'coordsList'
			);
		}

		foreach ($coordsList as $coords)
		{
			if (
				!is_array($coords)
				|| !isset($coords['lat'], $coords['lng'])
				|| !is_numeric($coords['lat'])
				|| !is_numeric($coords['lng'])
			)
			{
				throw new ArgumentException(
					'Each coordinate must contain numeric "lat" and "lng"',
					'coordsList'
				);
			}
		}

		try
		{
			return $this->repository->findByCoordsList(
				$coordsList,
				$zoom,
				$languageId,
				LOCATION_SEARCH_SCOPE_EXTERNAL
			);
		}
		catch (RuntimeException $exception)
		{
			$this->processException($exception);
		}

		// Keep the index-aligned contract on operational failure: null per input position.
		return array_fill_keys(array_keys($coordsList), null);
	}

	/**
	 * @param array $params
	 * @param int $searchScope
	 * @return array
	 */
	public function autocomplete(array $params, int $searchScope = LOCATION_SEARCH_SCOPE_ALL)
	{
		$result = [];

		try
		{
			$result = $this->repository->autocomplete($params, $searchScope);
		}
		catch (RuntimeException $exception)
		{
			$this->processException($exception);
		}

		return $result;
	}

	/**
	 * Save Location
	 *
	 * @param Entity\Location $location
	 * @return Result
	 */
	public function save(Entity\Location $location): Result
	{
		return $this->repository->save($location);
	}

	/**
	 * Delete Location
	 *
	 * @param Entity\Location $location
	 * @return Result
	 */
	public function delete(Entity\Location $location): Result
	{
		return $this->repository->delete($location);
	}

	/**
	 * LocationService constructor.
	 *
	 * @param Config\Container $config
	 */
	protected function __construct(Config\Container $config)
	{
		$this->setRepository($config->get('repository'));

		parent::__construct($config);
	}

}

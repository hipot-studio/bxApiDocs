<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Service;

use Bitrix\AI\Engine\Cloud\CloudEngine;
use Bitrix\AI\Engine\Repository\BitrixEngineRepository;
use Bitrix\AI\Engine;
use Bitrix\AI\Engine\Enum\Category;
use Bitrix\AI\Engine\Service\Dto\DataForUpdateBitrixEngineDto;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class BitrixEngineService
{
	protected array $engines;
	protected bool $hasInitDefaultEngines = false;

	public function __construct(
		protected BitrixEngineRepository $bitrixEngineRepository
	)
	{
	}

	/**
	 * @param list<array{CLASS: string, CATEGORY: string}> $providers
	 * @return void
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function updateEngines(array $providers): void
	{
		$dataForUpdateBitrixEngineDto = $this->getDataForUpdate($providers);

		$this->bitrixEngineRepository->insertEngines($dataForUpdateBitrixEngineDto->forInsert);
		$this->bitrixEngineRepository->deactivateEngines($dataForUpdateBitrixEngineDto->forDeactivate);
		$this->bitrixEngineRepository->activateEngines($dataForUpdateBitrixEngineDto->forActivate);
	}

	/**
	 * @return list<array{CLASS: string, CATEGORY: string}>
	 */
	public function getActiveProviders(array $rulesData, string $region): array
	{
		$result = [];

		if (!empty($rulesData['regionList']) && is_array($rulesData['regionList']))
		{
			[$result, $hasRegion] = $this->getProvidersByRegion($region, $rulesData['regionList']);

			if ($hasRegion)
			{
				return $result;
			}
		}

		if (empty($rulesData['default']['activeProviders']))
		{
			return $result;
		}

		return $this->getActiveProvidersData($rulesData['default']['activeProviders']);
	}

	/**
	 * @param list<array{regions: string[], activeProviders: string[]}> $rulesData
	 * @return array{list<array{CLASS: string, CATEGORY: string}>, bool}
	 */
	protected function getProvidersByRegion(string $region, array $rulesData): array
	{
		$result = [];

		foreach ($rulesData as $regionData)
		{
			if (
				empty($regionData['regions'])
				|| !is_array($regionData['regions'])
				|| empty($regionData['activeProviders'])
				|| !is_array($regionData['activeProviders'])
			)
			{
				continue;
			}

			if (!in_array($region, $regionData['regions'], true))
			{
				continue;
			}

			return [
				$this->getActiveProvidersData($regionData['activeProviders']),
				true
			];
		}

		return [$result, false];
	}

	/**
	 * @return list<array{CLASS: string, CATEGORY: string}>
	 */
	protected function getActiveProvidersData(array $activeProviders): array
	{
		$result = [];

		foreach ($activeProviders as $category => $classesForActivate)
		{
			if (empty($classesForActivate))
			{
				continue;
			}

			foreach ($classesForActivate as $activeEngineData)
			{
				if (!empty($activeEngineData['class']) && class_exists($activeEngineData['class']))
				{
					$result[$category . str_replace('\\', '', $activeEngineData['class'])] = [
						'CLASS' => $activeEngineData['class'],
						'CATEGORY' => $category
					];
				}
			}
		}

		return $result;
	}

	/**
	 * @param list<array{CLASS: string, CATEGORY: string}> $providers
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function getDataForUpdate(array $providers): DataForUpdateBitrixEngineDto
	{
		$forDeactivate = [];
		$forActivate = [];

		$enginesInDB = $this->bitrixEngineRepository->getAll();
		if (empty($enginesInDB))
		{
			return new DataForUpdateBitrixEngineDto($providers, $forDeactivate, $forActivate);
		}

		foreach ($enginesInDB as $engineData)
		{
			if (
				!isset($engineData['ID'], $engineData['CLASS'], $engineData['CATEGORY'], $engineData['IS_ACTIVE'])
				|| !is_string($engineData['CATEGORY']) || !is_string($engineData['CLASS'])
			)
			{
				continue;
			}

			$key = $engineData['CATEGORY'] . str_replace('\\', '', $engineData['CLASS']);
			if (!array_key_exists($key, $providers))
			{
				if (!$engineData['IS_ACTIVE'])
				{
					continue;
				}

				$forDeactivate[] = (int)$engineData['ID'];

				continue;
			}

			unset($providers[$key]);

			if ($engineData['IS_ACTIVE'])
			{
				continue;
			}

			$forActivate[] = (int)$engineData['ID'];
		}

		return new DataForUpdateBitrixEngineDto($providers, $forDeactivate, $forActivate);
	}

	public function initDefaultEngines(): void
	{
		if (!$this->needSetEngines())
		{
			return;
		}

		$engines = $this->getEngines();
		if (empty($engines))
		{
			return;
		}

		foreach ($engines as $engine)
		{
			if (
				empty($engine['CLASS'])
				|| empty($engine['CATEGORY'])
				|| !is_subclass_of($engine['CLASS'], CloudEngine::class)
			)
			{
				continue;
			}

			$category = Category::tryFrom($engine['CATEGORY']);
			if ($category !== null)
			{
				Engine::addEngine($category, $engine['CLASS']);
			}
		}
	}

	protected function needSetEngines(): bool
	{
		if ($this->hasInitDefaultEngines)
		{
			return false;
		}

		$this->hasInitDefaultEngines = true;

		return Bitrix24::shouldUseB24() === false;
	}

	/**
	 * @return list<array{CLASS: string, CATEGORY: string}>
	 */
	public function getEngines(): array
	{
		if (!isset($this->engines))
		{
			$this->engines = $this->bitrixEngineRepository->getActiveEngines();
		}

		return $this->engines;
	}
}

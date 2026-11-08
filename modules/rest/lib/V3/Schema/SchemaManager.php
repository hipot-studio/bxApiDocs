<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Dto\Generator;

final class SchemaManager
{
	private const ROUTING_CACHE_KEY = 'rest.v3.SchemaManager.routing.cache.key';
	private const METHOD_DESCRIPTIONS_CACHE_KEY = 'rest.v3.SchemaManager.methodDescriptions.cache.key';

	private ControllerDataManager $controllerDataManager;
	public function __construct()
	{
		$this->controllerDataManager = ServiceLocator::getInstance()->get(ControllerDataManager::class);
	}

	public function getRouteAliases(): array
	{
		$routes = CacheManager::get(self::ROUTING_CACHE_KEY);
		if ($routes === null)
		{
			$modulesConfig = ServiceLocator::getInstance()->get(ModuleManager::class)->getConfigs();

			foreach ($modulesConfig as $moduleConfig)
			{
				if (empty($moduleConfig->routes))
				{
					continue;
				}

				foreach ($moduleConfig->routes as $route => $routeMethod)
				{
					$routes[$route] = strtolower($routeMethod);
				}
			}

			CacheManager::set(self::ROUTING_CACHE_KEY, $routes);
		}

		return $routes;
	}

	/**
	 * @return MethodDescription[]
	 */
	public function getMethodDescriptions(): array
	{
		$methodDescriptionsCacheData = CacheManager::get(self::METHOD_DESCRIPTIONS_CACHE_KEY);
		$methodDescriptions = [];
		if ($methodDescriptionsCacheData === null)
		{
			$batchMethodDescription = new MethodDescription(
				module: 'rest',
				controllerFqcn: null,
				method: 'execute',
				dtoFqcn: null,
				scopes: [\CRestUtil::GLOBAL_SCOPE, 'rest', 'rest.batch'],
				actionUri: 'batch',
				title: new LocalizableMessage(code: 'REST_V3_SCHEMA_SCHEMAMANAGER_BATCH_ACTION_TITLE', phraseSrcFile: __FILE__),
				description: new LocalizableMessage(code: 'REST_V3_SCHEMA_SCHEMAMANAGER_BATCH_ACTION_DESCRIPTION', phraseSrcFile: __FILE__),
			);

			$methodDescriptions[$batchMethodDescription->actionUri] = $batchMethodDescription;

			$controllersData = $this->controllerDataManager->getData()['byName'];

			/** @var ControllerData $controllerData */
			foreach ($controllersData as $controllerData)
			{
				foreach ($controllerData->getMethods() as $methodDescription)
				{
					$methodDescriptions[$methodDescription->actionUri] = $methodDescription;
				}
			}

			foreach ($methodDescriptions as $methodDescription)
			{
				$methodDescriptionsCacheData[$methodDescription->actionUri] = $methodDescription;
				CacheManager::set($this->getActionCacheKey($methodDescription->actionUri), $methodDescription, CacheManager::ONE_HOUR_TTL);
			}
			CacheManager::set(self::METHOD_DESCRIPTIONS_CACHE_KEY, $methodDescriptionsCacheData, CacheManager::ONE_HOUR_TTL);
		}
		else
		{
			foreach ($methodDescriptionsCacheData as $actionUri => $methodDescription)
			{
				$methodDescriptions[$actionUri] = $methodDescription;
			}
		}

		return $methodDescriptions;
	}

	public function getMethodDescription(string $actionUri): ?MethodDescription
	{
		$methodDescription = CacheManager::get($this->getActionCacheKey($actionUri));
		if ($methodDescription === null)
		{
			$methodDescription = $this->getMethodDescriptions()[$actionUri] ?? null;
		}

		return $methodDescription;
	}

	public function getControllerDataByName(string $name): ?ControllerData
	{
		return $this->controllerDataManager->getByName($name);
	}

	public function getControllersByModules(): array
	{
		return $this->controllerDataManager->getData()['byModule'] ?? [];
	}

	/**
	 * Materializes eval-generated DTO classes for trusted bulk consumers
	 * (documentation, scope list). Must not be called from pre-auth method lookup.
	 *
	 * @param MethodDescription[] $methodDescriptions
	 */
	public function ensureGeneratedDtoClassesLoadedFromMethodDescriptions(array $methodDescriptions): void
	{
		$dtoFqcns = [];
		foreach ($methodDescriptions as $methodDescription)
		{
			if ($methodDescription->dtoFqcn !== null)
			{
				$dtoFqcns[$methodDescription->dtoFqcn] = $methodDescription->dtoFqcn;
			}

			$dtoClass = $methodDescription->queryParams['dtoClass'] ?? null;
			if (is_string($dtoClass))
			{
				$dtoFqcns[$dtoClass] = $dtoClass;
			}
		}

		if ($dtoFqcns === [])
		{
			return;
		}

		foreach ($this->controllerDataManager->getGeneratedDtoClosure(...$dtoFqcns) as $generatedDto)
		{
			Generator::generateByDto($generatedDto);
		}
	}

	private function ensureGeneratedDtoClassesLoadedForModules(string ...$moduleIds): void
	{
		$this->controllerDataManager->ensureGeneratedDtoClassesLoaded(...$moduleIds);
	}

	private function getActionCacheKey(string $action): string
	{
		return self::METHOD_DESCRIPTIONS_CACHE_KEY . '.' . $action;
	}
}

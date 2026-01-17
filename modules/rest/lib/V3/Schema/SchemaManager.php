<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\ClassLocator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Enabled;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Interaction\Response\Response;
use ReflectionClass;
use ReflectionMethod;

final class SchemaManager
{
	private const ROUTING_CACHE_KEY = 'rest.v3.SchemaManager.routing.cache.key';
	private const CONTROLLERS_DATA_CACHE_KEY = 'rest.v3.SchemaManager.controllersData.cache.key';
	private const METHOD_DESCRIPTIONS_CACHE_KEY = 'rest.v3.SchemaManager.methodDescriptions.cache.key';

	public const GENERATED_DTO_CACHE_KEY = 'rest.v3.SchemaManager.generatedDto.cache.key';

	private array $reflections = [];

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
				controller: null,
				method: 'execute',
				dtoClass: null,
				scopes: [\CRestUtil::GLOBAL_SCOPE, 'rest', 'rest.batch'],
				actionUri: 'batch',
				title: new LocalizableMessage(code: 'REST_V3_SCHEMA_SCHEMAMANAGER_BATCH_ACTION_TITLE', phraseSrcFile: __FILE__),
				description: new LocalizableMessage(code: 'REST_V3_SCHEMA_SCHEMAMANAGER_BATCH_ACTION_DESCRIPTION', phraseSrcFile: __FILE__),
			);

			$methodDescriptions[$batchMethodDescription->actionUri] = $batchMethodDescription;

			$controllersData = $this->getControllersData()['byName'];

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

		if ($methodDescription === null)
		{
			return $methodDescription;
		}

		$generatedDtos = $this->getGeneratedDtosByModuleId($methodDescription->module);
		foreach ($generatedDtos as $generatedDto)
		{
			Generator::generateByDto($generatedDto);
		}

		return $methodDescription;
	}

	private function getDtoClassFromAttributes(ReflectionClass $controllerReflection): ?string
	{
		$dtoTypeAttributes = $controllerReflection->getAttributes(DtoType::class);
		if (count($dtoTypeAttributes) > 1)
		{
			throw new TooManyAttributesException($controllerReflection->getName(), DtoType::class, 1);
		}

		foreach ($dtoTypeAttributes as $attribute)
		{
			/** @var DtoType $instance */
			$instance = $attribute->newInstance();
			if (!isset($this->reflections[$instance->type]))
			{
				$dtoReflection = new ReflectionClass($instance->type);
				if (!$dtoReflection->isSubclassOf(Dto::class))
				{
					return null;
				}
				$this->reflections[$instance->type] = $dtoReflection;
			}

			return $instance->type;
		}

		return null;
	}

	public function getControllerDataByName(string $name): ?ControllerData
	{
		$controllerCacheData = CacheManager::get($this->getControllerCacheKey($name));
		if ($controllerCacheData === null)
		{
			$controllersData = $this->getControllersData();
			$controllerData = $controllersData['byName'][$name] ?? null;
		}
		else
		{
			$controllerData = ControllerData::fromArray($controllerCacheData);
		}

		return $controllerData;
	}

	public function getControllersByModules(): array
	{
		$controllersData = $this->getControllersData();

		return $controllersData['byModule'] ?? [];
	}

	private function getControllersData(): array
	{
		$controllersData = [
			'byName' => [],
			'byModule' => [],
		];

		$items = CacheManager::get(self::CONTROLLERS_DATA_CACHE_KEY);
		if ($items !== null)
		{
			foreach ($items as $controllerCacheData)
			{
				$controllerData = ControllerData::fromArray($controllerCacheData);
				$controllersData['byName'][$controllerData->controller->getName()]
				= $controllersData['byModule'][$controllerData->module][$controllerData->controller->getName()] = $controllerData;
			}
		}
		else
		{
			$controllersCacheData = [];
			$modulesConfig = ServiceLocator::getInstance()->get(ModuleManager::class)->getConfigs();
			foreach ($modulesConfig as $moduleId => $moduleConfig)
			{
				$generatedDtoCacheData = [];
				if (!Loader::includeModule($moduleId))
				{
					continue;
				}

				$namespaces = array_merge(
					[$moduleConfig->defaultNamespace],
					$moduleConfig->namespaces,
				);

				$customControllerData = [];

				if (
					$moduleConfig->schemaProviderClass !== null
					&& class_exists($moduleConfig->schemaProviderClass)
					&& is_subclass_of($moduleConfig->schemaProviderClass, SchemaProvider::class)
				) {
					/** @var SchemaProvider $schemaProvider */
					$schemaProvider = new ($moduleConfig->schemaProviderClass);
					/** @var GeneratedDto $generatedDto */
					foreach ($schemaProvider->getDataForDtoGeneration() as $generatedDto)
					{
						if (!$generatedDto instanceof GeneratedDto)
						{
							throw new \InvalidArgumentException('SchemaProvider::getDataForDtoGeneration must return array of GeneratedDto instances.');
						}
						Generator::generateByDto($generatedDto);
						$generatedDtoCacheData[] = $generatedDto;
					}

					/** @var ControllerData $controllerData */
					foreach ($schemaProvider->getControllersData() as $controllerData)
					{
						$customControllerData[$controllerData->controller->getName()] = $controllerData;
					}
				}

				foreach ($namespaces as $namespace)
				{
					$classes = ClassLocator::getClassesByNamespace($namespace);
					foreach ($classes as $controllerClass)
					{
						$controllerReflection = new ReflectionClass($controllerClass);
						if (!$controllerReflection->isSubclassOf(RestController::class))
						{
							continue;
						}

						$dtoClass = $this->getDtoClassFromAttributes($controllerReflection);

						$controllerData = new ControllerData(
							module: $moduleId,
							controller: $controllerClass,
							dto: $dtoClass,
							namespace: $namespace,
						);

						$this->addMethodsToControllerData($controllerData);
						if (isset($customControllerData[$controllerClass]))
						{
							foreach ($customControllerData[$controllerClass]->getMethods() as $customMethodDescription)
							{
								$controllerData->addMethod($customMethodDescription);
							}
							unset($customControllerData[$controllerClass]);
						}

						$controllerCacheData = $controllerData->toArray();

						CacheManager::set($this->getControllerCacheKey($controllerReflection->getName()), $controllerCacheData, CacheManager::ONE_HOUR_TTL);
						$controllersCacheData[$controllerReflection->getName()] = $controllerCacheData;
						$controllersData['byName'][$controllerReflection->getName()]
							= $controllersData['byModule'][$controllerData->module][$controllerReflection->getName()] = $controllerData;
					}
				}

				foreach ($customControllerData as $controllerData)
				{
					$controllerCacheData = $controllerData->toArray();

					CacheManager::set($this->getControllerCacheKey($controllerData->controller->getName()), $controllerCacheData, CacheManager::ONE_HOUR_TTL);
					$controllersCacheData[$controllerData->controller->getName()] = $controllerCacheData;
					$controllersData['byName'][$controllerData->controller->getName()]
						= $controllersData['byModule'][$controllerData->module][$controllerData->controller->getName()] = $controllerData;
				}
				$this->saveGeneratedDtosByModuleId($moduleId, $generatedDtoCacheData);
			}
			CacheManager::set(self::CONTROLLERS_DATA_CACHE_KEY, $controllersCacheData, CacheManager::ONE_HOUR_TTL);
		}

		return $controllersData;
	}

	private function addMethodsToControllerData(ControllerData $controllerData): void
	{
		foreach ($controllerData->controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
		{
			if (!str_ends_with($method->name, 'Action'))
			{
				continue;
			}

			$methodName = str_replace('Action', '', $method->name);

			$returnType = $method->getReturnType()?->getName();

			if ($returnType === null || !is_subclass_of($returnType, Response::class))
			{
				continue;
			}

			$actionUri = $controllerData->getMethodUri($methodName);

			$scopeParts = explode('.', $actionUri);
			$scopeString = $scopeParts[0];
			$scopes = [$scopeString];
			$scopesCount = count($scopeParts);
			for ($i = 1; $i < $scopesCount; $i++)
			{
				if (!isset($scopeParts[$i]))
				{
					break;
				}
				$scopeString .= '.' . $scopeParts[$i];
				$scopes[] = $scopeString;
			}

			$methodDescriptionData = [
				'module' => $controllerData->module,
				'method' => $methodName,
				'controller' => $controllerData->controller->name,
				'dtoClass' => $controllerData->dto?->getName(),
				'scopes' => $scopes,
				'actionUri' => $actionUri,
				'title' => null,
				'description' => null,
				'isEnabled' => true,
				'queryParams' => null,
			];

			foreach ($method->getAttributes() as $attribute)
			{
				$attributeName = $attribute->getName();
				$attributeInstance = $attribute->newInstance();

				match ($attributeName)
				{
					Scope::class => $methodDescriptionData['scopes'][] = $attributeInstance->value,
					Title::class => $methodDescriptionData['title'] = $attributeInstance->value,
					Description::class => $methodDescriptionData['description'] = $attributeInstance->value,
					Enabled::class => call_user_func(function () use ($attributeInstance, &$methodDescriptionData) {
						/** @var CheckEnabledProvider $provider */
						$provider = new $attributeInstance->provider();
						$methodDescriptionData['isEnabled'] = $provider->isEnabled();
					}),
					default => null,
				};
			}

			$methodDescription = new MethodDescription(
				module: $methodDescriptionData['module'],
				controller: $methodDescriptionData['controller'],
				method: $methodDescriptionData['method'],
				dtoClass: $methodDescriptionData['dtoClass'],
				scopes: array_unique($methodDescriptionData['scopes']),
				actionUri: $methodDescriptionData['actionUri'],
				title: $methodDescriptionData['title'],
				description: $methodDescriptionData['description'],
				isEnabled: $methodDescriptionData['isEnabled'],
				queryParams: $methodDescriptionData['queryParams'],
			);

			$controllerData->addMethod($methodDescription);
		}
	}

	private function getControllerCacheKey(string $controllerName): string
	{
		return self::CONTROLLERS_DATA_CACHE_KEY . '.' . $controllerName;
	}

	private function getActionCacheKey(string $action): string
	{
		return self::METHOD_DESCRIPTIONS_CACHE_KEY . '.' . $action;
	}

	private function saveGeneratedDtosByModuleId(string $moduleId, array $generatedDtoCacheData)
	{
		CacheManager::set(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId, $generatedDtoCacheData, CacheManager::ONE_HOUR_TTL);
	}

	/**
	 * @param string $moduleId
	 * @return GeneratedDto[]
	 */
	private function getGeneratedDtosByModuleId(string $moduleId): array
	{
		$dtos = CacheManager::get(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId);

		return $dtos !== null ? $dtos : [];
	}
}

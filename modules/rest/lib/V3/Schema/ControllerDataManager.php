<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\ClassLocator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Enabled;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Realisation\Controller\Field;
use Bitrix\Rest\V3\Realisation\Controller\Field\Custom;
use Bitrix\Rest\V3\Realisation\Dto\DtoFieldDto;
use Bitrix\Rest\V3\Realisation\Dto\Field\Custom\EnumDto;
use Bitrix\Rest\V3\Realisation\Dto\Field\CustomDto;
use ReflectionClass;
use ReflectionMethod;

final class ControllerDataManager
{
	private const CONTROLLERS_DATA_CACHE_KEY = 'rest.v3.ControllerDataManager.controllersData.cache.key';

	private const GENERATED_DTO_CACHE_KEY = 'rest.v3.ControllerDataManager.generatedDto.cache.key';

	private static array $byName;

	private static array $byModule;

	private array $systemControllersData = [];

	private array $reflections = [];

	private array $dtos = [];

	public function getData()
	{
		if (isset(self::$byName) && isset(self::$byModule))
		{
			return [
				'byName' => self::$byName,
				'byModule' => self::$byModule,
			];
		}

		$items = CacheManager::get(self::CONTROLLERS_DATA_CACHE_KEY);
		if ($items !== null)
		{
			foreach ($items as $controllerCacheData)
			{
				if (!Loader::includeModule($controllerCacheData['module']))
				{
					continue;
				}

				$controllerData = ControllerData::fromArray($controllerCacheData);
				self::$byName[$controllerData->controllerFqcn] = $controllerData;
				self::$byModule[$controllerData->module][$controllerData->controllerFqcn] = $controllerData;
			}
		}
		else
		{
			$controllersCacheData = [];

			$this->systemControllersData[Field::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Field::class,
				dtoFqcn: DtoFieldDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

			$this->systemControllersData[Custom::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Custom::class,
				dtoFqcn: CustomDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

			$this->systemControllersData[Custom\Enum::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Custom\Enum::class,
				dtoFqcn: EnumDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

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
					$schemaProvider = new ($moduleConfig->schemaProviderClass);

					foreach ($schemaProvider->getDataForDtoGeneration() as $generatedDto)
					{
						if (!$generatedDto instanceof GeneratedDto)
						{
							throw new \InvalidArgumentException('SchemaProvider::getDataForDtoGeneration must return array of GeneratedDto instances.');
						}

						Generator::generateByDto($generatedDto);
						$generatedDtoCacheData[] = $generatedDto;
					}

					foreach ($schemaProvider->getControllersData() as $controllerData)
					{
						$customControllerData[$controllerData->controllerFqcn] = $controllerData;
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
						$isEnabled = $this->resolveControllerEnabled($controllerReflection);

						$controllerData = new ControllerData(
							module: $moduleId,
							controllerFqcn: $controllerClass,
							dtoFqcn: $dtoClass,
							namespace: $namespace,
							enabled: $isEnabled,
						);

						$this->addMethodDescriptionsByControllerReflection($controllerData, $controllerReflection);

						$dto = $this->getDtoByClass($dtoClass);
						if ($dto !== null)
						{
							$this->addDtoFieldMethods($dto, $controllerData);
						}

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

						self::$byName[$controllerReflection->getName()] = $controllerData;
						self::$byModule[$controllerData->module][$controllerReflection->getName()] = $controllerData;
					}
				}

				foreach ($customControllerData as $controllerData)
				{
					$controllerCacheData = $controllerData->toArray();

					CacheManager::set($this->getControllerCacheKey($controllerData->controllerFqcn), $controllerCacheData, CacheManager::ONE_HOUR_TTL);
					$controllersCacheData[$controllerData->controllerFqcn] = $controllerCacheData;

					self::$byName[$controllerData->controllerFqcn] = $controllerData;
					self::$byModule[$controllerData->module][$controllerData->controllerFqcn] = $controllerData;
				}

				$this->saveGeneratedDtosByModuleId($moduleId, $generatedDtoCacheData);
			}

			foreach ($this->systemControllersData as $systemControllerData)
			{
				$systemControllerCacheData = $systemControllerData->toArray();

				CacheManager::set($this->getControllerCacheKey($systemControllerData->controllerFqcn), $systemControllerCacheData, CacheManager::ONE_HOUR_TTL);
				$controllersCacheData[$systemControllerData->controllerFqcn] = $systemControllerCacheData;

				self::$byName[$systemControllerData->controllerFqcn] = $systemControllerData;
				self::$byModule[$systemControllerData->module][$systemControllerData->controllerFqcn] = $systemControllerData;
			}

			CacheManager::set(self::CONTROLLERS_DATA_CACHE_KEY, $controllersCacheData, CacheManager::ONE_HOUR_TTL);
		}

		return [
			'byName' => self::$byName,
			'byModule' => self::$byModule,
		];
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

	private function getScopesFromActionUri(string $actionUri): array
	{
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

		return $scopes;
	}

	private function resolveControllerEnabled(ReflectionClass $controllerReflection): bool
	{
		$enabledAttributes = $controllerReflection->getAttributes(Enabled::class);
		if ($enabledAttributes === [])
		{
			return true;
		}

		$enabledAttribute = $enabledAttributes[0]->newInstance();

		return $enabledAttribute->isEnabled();
	}

	private function getControllerCacheKey(string $controllerName): string
	{
		return self::CONTROLLERS_DATA_CACHE_KEY . '.' . $controllerName;
	}

	private function saveGeneratedDtosByModuleId(string $moduleId, array $generatedDtoCacheData): void
	{
		CacheManager::set(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId, $generatedDtoCacheData, CacheManager::ONE_HOUR_TTL);
	}

	private function getDtoByClass(?string $dtoClass): ?Dto
	{
		if ($dtoClass === null)
		{
			return null;
		}

		if (!isset($this->dtos[$dtoClass]))
		{
			try
			{
				$this->dtos[$dtoClass] = $dtoClass::create();
			}
			catch (\Exception)
			{
				return null;
			}
		}

		return $this->dtos[$dtoClass];
	}

	private function addDtoFieldMethods(Dto $dto, ControllerData $controllerData): void
	{
		$listMethodUri = $controllerData->getMethodUri('field.list');

		$listMethodDescription = new MethodDescription(
			module: $controllerData->module,
			controllerFqcn: Field::class,
			method: 'list',
			dtoFqcn: DtoFieldDto::class,
			scopes: $this->getScopesFromActionUri($listMethodUri),
			actionUri: $listMethodUri,
			title: null,
			description: null,
			queryParams: [
				'dtoClass' => $dto::class
			]
		);

		$this->systemControllersData[Field::class]->addMethod($listMethodDescription);

		$getMethodUri = $controllerData->getMethodUri('field.get');

		$getMethodDescription = new MethodDescription(
			module: $controllerData->module,
			controllerFqcn: Field::class,
			method: 'get',
			dtoFqcn: DtoFieldDto::class,
			scopes: $this->getScopesFromActionUri($getMethodUri),
			actionUri: $getMethodUri,
			title: null,
			description: null,
			queryParams: [
				'dtoClass' => $dto::class
			]
		);

		$this->systemControllersData[Field::class]->addMethod($getMethodDescription);

		if ($dto->issetUserFields())
		{
			$entityId = $dto->getAttributeByName(OrmEntity::class)->getUserFieldId();
			$customMethods = ['list', 'get', 'delete', 'update', 'add'];
			foreach ($customMethods as $customMethod)
			{
				$customMethodUri = $controllerData->getMethodUri('field.custom.' . $customMethod);
				$customMethodDescription = new MethodDescription(
					module: $controllerData->module,
					controllerFqcn: Custom::class,
					method: $customMethod,
					dtoFqcn: CustomDto::class,
					scopes: $this->getScopesFromActionUri($customMethodUri),
					actionUri: $customMethodUri,
					title: null,
					description: null,
					queryParams: ['entityId' => $entityId],
				);
				$this->systemControllersData[Custom::class]->addMethod($customMethodDescription);
				$enumMethodUri = $controllerData->getMethodUri('field.custom.enum.' . $customMethod);
				$customEnumMethodDescription = new MethodDescription(
					module: $controllerData->module,
					controllerFqcn: Custom\Enum::class,
					method: $customMethod,
					dtoFqcn: EnumDto::class,
					scopes: $this->getScopesFromActionUri($enumMethodUri),
					actionUri: $enumMethodUri,
					title: null,
					description: null,
					queryParams: ['entityId' => $entityId],
				);
				$this->systemControllersData[Custom\Enum::class]->addMethod($customEnumMethodDescription);
			}
		}
	}

	public function getByName(string $name)
	{
		$controllerCacheData = CacheManager::get($this->getControllerCacheKey($name));
		if ($controllerCacheData === null)
		{
			
			$controllersData = $this->getData();
			$controllerData = $controllersData['byName'][$name] ?? null;
		}
		else
		{
			$controllerData = ControllerData::fromArray($controllerCacheData);
		}

		return $controllerData;
	}

	/**
	 * @param string $moduleId
	 * @return GeneratedDto[]
	 */
	public function getGeneratedDtosByModuleId(string $moduleId): array
	{
		$dtos = CacheManager::get(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId);

		return $dtos !== null ? $dtos : [];
	}

	private function addMethodDescriptionsByControllerReflection(ControllerData $controllerData, ReflectionClass $controllerReflection): void
	{
		foreach ($controllerReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
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
			$scopes = $this->getScopesFromActionUri($actionUri);

			$methodDescriptionData = [
				'module' => $controllerData->module,
				'method' => $methodName,
				'controllerFqcn' => $controllerData->controllerFqcn,
				'dtoFqcn' => $controllerData->dtoFqcn,
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
						$provider = new $attributeInstance->provider();
						$methodDescriptionData['isEnabled'] = $provider->isEnabled();
					}),
					default => null,
				};
			}

			$methodDescription = new MethodDescription(
				module: $methodDescriptionData['module'],
				controllerFqcn: $methodDescriptionData['controllerFqcn'],
				method: $methodDescriptionData['method'],
				dtoFqcn: $methodDescriptionData['dtoFqcn'],
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
}
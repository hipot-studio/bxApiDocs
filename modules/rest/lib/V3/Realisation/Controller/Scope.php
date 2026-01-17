<?php

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Schema\SchemaManager;

class Scope extends RestController
{
	private const SCOPE_CACHE_KEY = 'rest.v3.scope.cache.key';

	#[Title(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_LIST_ACTION_TITLE'))]
	#[Description(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_LIST_ACTION_DESCRIPTION'))]
	public function listAction(?string $filterModule = null, ?string $filterController = null, ?string $filterMethod = null): ArrayResponse
	{
		$cacheKey = self::SCOPE_CACHE_KEY . '.' . $this->responseLanguage;

		$scopes = CacheManager::get($cacheKey);
		if ($scopes === null)
		{
			/** @var SchemaManager $schemaManager */
			$schemaManager = ServiceLocator::getInstance()->get(SchemaManager::class);
			$methodDescriptions = $schemaManager->getMethodDescriptions();

			$scopes = [];

			/** @var Dto[] $dtos */
			$dtos = [];

			$dtoFields = [];

			foreach ($methodDescriptions as $methodDescription)
			{
				if ($methodDescription->controller)
				{
					$controllerData = $schemaManager->getControllerDataByName($methodDescription->controller);
					if ($controllerData->dto && empty($dtos[$methodDescription->controller]))
					{
						$dtos[$methodDescription->controller] = $controllerData->dto->getName()::create();

						/** @var DtoField $dtoField */
						foreach ($dtos[$methodDescription->controller]->getFields() as $dtoField)
						{
							$dtoFieldData = [
								'name' => $dtoField->getPropertyName(),
								'title' => $dtoField->getTitle() instanceof LocalizableMessage ? $dtoField->getTitle()->localize($this->responseLanguage) : $dtoField->getTitle(),
								'description' => $dtoField->getDescription() instanceof LocalizableMessage ? $dtoField->getDescription()->localize($this->responseLanguage) : $dtoField->getDescription(),
							];

							$dtoFields[$methodDescription->controller][$dtoField->getPropertyName()] = $dtoFieldData;
						}
						$dtoFields[$methodDescription->controller] = array_values($dtoFields[$methodDescription->controller]);
					}
				}

				$scopeFields = $methodDescription->controller ? $dtoFields[$methodDescription->controller] : null;

				foreach ($methodDescription->scopes as $scope)
				{
					$parts = explode('.', $scope);
					$currentModule = $parts[0] ?? null;
					$currentController = implode('.', array_slice($parts, 1, -1)) ?: null;
					$currentMethod = end($parts) ?: null;

					if ($currentModule && $currentController && $currentMethod && !isset($scopes[$currentModule][$currentController][$currentMethod]))
					{
						// scope: module.controller.method
						$scopes[$currentModule][$currentController][$currentMethod] = [
							'scope' => $scope,
							'title' => $methodDescription->title instanceof LocalizableMessage ? $methodDescription->title->localize($this->responseLanguage) : $methodDescription->title,
							'description' => $methodDescription->description instanceof LocalizableMessage ? $methodDescription->description->localize($this->responseLanguage) : $methodDescription->description,
							'fields' => $scopeFields,
						];
					}
					elseif ($currentModule && $currentController && !isset($scopes[$currentModule][$currentController]['*']))
					{
						// scope: module.controller
						$scopes[$currentModule][$currentController]['*'] = [
							'scope' => $scope,
							'title' => (new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_ALL_CONTROLLER_METHODS_TITLE'))->localize($this->responseLanguage),
							'description' => (new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_ALL_CONTROLLER_METHODS_DESCRIPTION'))->localize($this->responseLanguage),
							'fields' => $scopeFields,
						];
					}
					elseif ($currentModule && !isset($scopes[$currentModule]['*']))
					{
						// Scope: module
						$scopes[$currentModule]['*']['*'] = [
							'scope' => $scope,
							'title' => (new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_ALL_MODULE_METHODS_TITLE'))->localize($this->responseLanguage),
							'description' => (new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_SCOPE_ALL_MODULE_METHODS_DESCRIPTION'))->localize($this->responseLanguage),
							'fields' => null,
						];
					}
				}
			}

			unset($scopes['_global']);

			CacheManager::set($cacheKey, $scopes);
		}

		$result = $this->filterScopes($scopes, $filterModule, $filterController, $filterMethod);

		return new ArrayResponse($result);
	}

	private function filterScopes(array $scopes, ?string $filterModule, ?string $filterController, ?string $filterMethod): array
	{
		if ($filterModule === null && $filterController === null && $filterMethod === null)
		{
			return $scopes;
		}

		$result = [];

		foreach ($scopes as $module => $moduleData)
		{
			if ($filterModule !== null && $module !== $filterModule)
			{
				continue;
			}

			foreach ($moduleData as $controller => $controllerData)
			{
				if ($filterController !== null && $controller !== $filterController)
				{
					continue;
				}

				foreach ($controllerData as $method => $methodData)
				{
					if ($filterMethod !== null && $method !== $filterMethod)
					{
						continue;
					}

					if ($filterModule !== null)
					{
						if ($filterController !== null)
						{
							$result[$module][$controller][$method] = $methodData;
						}
						else
						{
							$result[$module][$controller][$method] = $methodData;
						}
					}
					else
					{
						$result[$module][$controller][$method] = $methodData;
					}
				}
			}
		}

		return $result;
	}
}

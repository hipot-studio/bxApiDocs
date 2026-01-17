<?php

namespace Bitrix\Rest\V3\Documentation;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Documentation\Attributes\Deprecated;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\AggregateResponse;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Schema\ControllerData;
use Bitrix\Rest\V3\Schema\ModuleManager;
use Bitrix\Rest\V3\Schema\SchemaManager;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationType;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class DocumentationManager
{
	private SchemaManager $schemaManager;

	private ModuleManager $moduleManager;

	/** @var DtoExample[] */
	private static array $dtoExamples = [];

	private const AVAILABLE_DEFAULT_RESPONSES = [
		ArrayResponse::class,
		GetResponse::class,
		ListResponse::class,
		AddResponse::class,
		BooleanResponse::class,
		DeleteResponse::class,
		UpdateResponse::class,
		AggregateResponse::class,
	];

	public function __construct(private readonly string $language)
	{
		$this->schemaManager = ServiceLocator::getInstance()->get(SchemaManager::class);
		$this->moduleManager = ServiceLocator::getInstance()->get(ModuleManager::class);
	}

	/**
	 * @throws ReflectionException
	 */
	public function generateDataForJson(): array
	{
		$dtoSchemas = [];

		$file = [
			'openapi' => '3.0.0',
			'info' => [
				'title' => 'Bitrix24 REST V3 API',
				'version' => '1.0.0',
			],
			'servers' => [],
			'tags' => [],
			'paths' => [],
			'components' => ['schemas' => $dtoSchemas],
		];

		$customModuleSchemas = $this->getCustomModuleSchemas();
		$customModuleMethods = $this->getCustomModuleMethods();
		$customModuleRoutes = $this->schemaManager->getRouteAliases();
		$moduleControllers = $this->schemaManager->getControllersByModules();
		$methodDescriptions = $this->schemaManager->getMethodDescriptions();

		foreach ($moduleControllers as $moduleId => $controllers)
		{
			$file['tags'][] = [
				'name' => $moduleId,
				'description' => $moduleId . ' module methods',
			];
			/** @var ControllerData $controllerData */
			foreach ($controllers as $controllerData)
			{
				if (!$controllerData->isEnabled())
				{
					continue;
				}
				if ($controllerData->dto !== null)
				{
					$this->collectDtoSchemas($controllerData->dto, $customModuleSchemas[$moduleId] ?? [], $dtoSchemas);
				}

				foreach ($controllerData->getMethods() as $controllerMethodDescription)
				{
					if (!$controllerMethodDescription->isEnabled)
					{
						continue;
					}
					if (isset($customModuleMethods[$controllerData->module][$controllerMethodDescription->actionUri]))
					{
						$methodData = $customModuleMethods[$controllerData->module][$controllerMethodDescription->actionUri];
					}
					else
					{
						$method = $controllerData->controller->getMethod($controllerMethodDescription->method . 'Action');
						$returnType = $method->getReturnType()->getName();
						$methodData = $this->getMethodData($method, $returnType, $controllerData);
						if ($methodData === null)
						{
							continue; // skip unknown return types
						}
					}
					unset($methodDescriptions[$controllerMethodDescription->actionUri]);
					$file['paths'][$this->getPathUri($controllerMethodDescription->actionUri)]['post'] = $methodData;
					if (isset($methodData['requestBody']['content']['application/json']['schema']['properties']) && empty($methodData['requestBody']['content']['application/json']['schema']['properties']))
					{

						$file['paths'][$this->getPathUri($controllerMethodDescription->actionUri)]['get'] = $methodData;
						unset($file['paths'][$this->getPathUri($controllerMethodDescription->actionUri)]['get']['requestBody']);
					}
				}
			}
		}

		foreach ($methodDescriptions as $methodDescription)
		{
			if (isset($customModuleMethods[$methodDescription->module][$methodDescription->actionUri]))
			{
				$methodData = $customModuleMethods[$methodDescription->module][$methodDescription->actionUri];
				$file['paths'][$this->getPathUri($methodDescription->actionUri)]['post'] = $methodData;
				unset($methodDescriptions[$methodDescription->actionUri]);
			}
		}

		foreach ($customModuleRoutes as $customRoute => $moduleRoute)
		{
			$moduleUri = $this->getPathUri($moduleRoute);

			if (isset($file['paths'][$moduleUri]))
			{
				$file['paths'][$this->getPathUri($customRoute)] = $file['paths'][$moduleUri];
			}
		}

		$file['components']['schemas'] = $dtoSchemas;

		return $file;
	}

	private function collectDtoSchemas(ReflectionClass $dtoReflection, array $customSchemas, array &$dtoSchemas): void
	{
		$dtoSchemaName = $this->getDtoSchemaName($dtoReflection);
		if (isset($dtoSchemas[$dtoSchemaName]))
		{
			return;
		}

		if (isset($customSchemas[$dtoSchemaName]))
		{
			$dtoSchemas[$dtoSchemaName] = $customSchemas[$dtoSchemaName];

			return;
		}

		$dtoSchema = $this->getDtoProperties($dtoReflection);
		$dtoSchemas[$dtoSchemaName] = $dtoSchema;

		/** @var DtoExample $instance */
		$instance = $dtoReflection->getName()::create();
		foreach ($instance->getFields() as $field)
		{
			$type = $field->getPropertyType();
			if (is_subclass_of($type, Dto::class))
			{
				$this->collectDtoSchemas(new ReflectionClass($type), $customSchemas, $dtoSchemas);
			}
			elseif ($type === DtoCollection::class && $field->getElementType() && is_subclass_of($field->getElementType(), Dto::class))
			{
				$this->collectDtoSchemas(new ReflectionClass($field->getElementType()), $customSchemas, $dtoSchemas);
			}
		}
	}

	private function getCustomModuleSchemas(): array
	{
		$documentationSchemas = [];
		$moduleConfigs = $this->moduleManager->getConfigs();
		foreach ($moduleConfigs as $moduleId => $moduleConfig)
		{
			if (!empty($moduleConfig->documentation['schemas']) && is_array($moduleConfig->documentation['schemas']))
			{
				foreach ($moduleConfig->documentation['schemas'] as $schemaObject => $schemaClass)
				{
					if (is_subclass_of($schemaClass, SchemaProvider::class))
					{
						$class = new $schemaClass();
						$documentationSchemas[$moduleId][$schemaObject] = $class->getDocumentation();
					}
				}
			}
		}

		return $documentationSchemas;
	}

	private function getCustomModuleMethods(): array
	{
		$documentationMethods = [];
		$moduleConfigs = $this->moduleManager->getConfigs();
		foreach ($moduleConfigs as $moduleId => $moduleConfig)
		{
			if (!empty($moduleConfig->documentation['methods']) && is_array($moduleConfig->documentation['methods']))
			{
				foreach ($moduleConfig->documentation['methods'] as $methodUri => $methodDocumentationClass)
				{
					if (is_subclass_of($methodDocumentationClass, MethodProvider::class))
					{
						$class = new $methodDocumentationClass($this->language);
						$documentationMethods[$moduleId][$methodUri] = $class->getDocumentation();
					}
				}
			}
		}

		return $documentationMethods;
	}

	private function getMethodResponseSchema(string $returnTypeClass, ?ReflectionClass $dtoReflection): array
	{
		$getResponseByClass = function (string $responseClass) use ($dtoReflection) {
			return match ($responseClass)
			{
				ArrayResponse::class => [
					'type' => 'object',
				],
				GetResponse::class => [
					'type' => 'object',
					'properties' => $dtoReflection ? [
						'item' => [
							'$ref' => '#/components/schemas/' . $this->getDtoSchemaName($dtoReflection),
						],
					] : [],
				],
				ListResponse::class => call_user_func(function () use ($dtoReflection) {
					$result = [
						'type' => 'array',
					];
					if ($dtoReflection !== null)
					{
						$result['items'] = ['$ref' => '#/components/schemas/' . $this->getDtoSchemaName($dtoReflection)];
					}

					return $result;
				}),
				AddResponse::class => [
					'type' => 'object',
					'properties' => [
						'id' => [
							'type' => 'integer',
							'format' => 'int64',
						],
					],
				],
				BooleanResponse::class, DeleteResponse::class, UpdateResponse::class => [
					'type' => 'object',
					'properties' => [
						'result' => [
							'type' => 'boolean',
						],
					],
				],
				AggregateResponse::class => [
					'type' => 'object',
					'properties' => [
						'result' => [
							'type' => 'object',
							'properties' => $this->aggregateProperties(),
						],
					],
				],
				default => [],
			};
		};

		$baseResponse = function (array $result = []) {
			return [
				'type' => 'object',
				'properties' => [
					'result' => $result,
				],
			];
		};

		if (in_array($returnTypeClass, self::AVAILABLE_DEFAULT_RESPONSES, true))
		{
			return $baseResponse($getResponseByClass($returnTypeClass));
		}

		foreach (self::AVAILABLE_DEFAULT_RESPONSES as $responseClass)
		{
			if (is_subclass_of($returnTypeClass, $responseClass))
			{
				return $baseResponse($getResponseByClass($responseClass));
			}
		}

		return $baseResponse();
	}

	private function aggregateProperties(): array
	{
		$aggregationProperties = [];
		foreach (AggregationType::cases() as $aggregationType)
		{
			$aggregationProperties[$aggregationType->value] = [
				'type' => 'object',
			];
		}

		return $aggregationProperties;
	}

	private function getFieldTypeFormat(DtoField $field): array
	{
		if ($field->isMultiple())
		{
			$result = [
				'type' => 'array',
				'items' => $this->getFormatByType($field),
			];
		}
		else
		{
			$result = $this->getFormatByType($field);
		}

		if ($field->getTitle() !== null)
		{
			$result['title'] = $field->getTitle() instanceof LocalizableMessage ? $field->getTitle()->localize($this->language) : $field->getTitle();
		}

		if ($field->getDescription() !== null)
		{
			$result['description'] = $field->getDescription() instanceof LocalizableMessage ? $field->getDescription()->localize($this->language) : $field->getDescription();
		}

		return $result;
	}

	private function getFormatByType(DtoField $field): array
	{
		$types = [
			'float' => ['type' => 'float'],
			'array' => ['type' => 'array'],
			'bool' => ['type' => 'boolean'],
			'int' => ['type' => 'integer', 'format' => 'int64'],
			'string' => ['type' => 'string'],
			DateTime::class => ['type' => 'string', 'format' => 'date-time'],
			Date::class => ['type' => 'string', 'format' => 'date'],
		];

		if (isset($types[$field->getPropertyType()]))
		{
			return $types[$field->getPropertyType()];
		}

		if ($field->getPropertyType() === DtoCollection::class)
		{
			return $this->getDtoCollectionProperty($field);
		}

		if (is_subclass_of($field->getPropertyType(), Dto::class))
		{
			$reflectionClass = new ReflectionClass($field->getPropertyType());

			return ['$ref' => '#/components/schemas/' . $this->getDtoSchemaName($reflectionClass)];
		}

		return [];
	}

	private function getDtoCollectionProperty(DtoField $field): array
	{
		if ($field->getElementType())
		{
			$reflectionClass = new ReflectionClass($field->getElementType());

			return [
				'$ref' => '#/components/schemas/' . $this->getDtoSchemaName($reflectionClass),
			];
		}

		return [];
	}

	private function getDtoProperties(ReflectionClass $dtoReflection): array
	{
		$result = [
			'type' => 'object',
			'properties' => [],
		];

		/** @var DtoExample $instance */
		$instance = $dtoReflection->getName()::create();
		/** @var DtoField $field */
		foreach ($instance->getFields() as $field)
		{
			$result['properties'][$field->getPropertyName()] = $this->getFieldTypeFormat($field);
		}

		return $result;
	}

	private function getRequestTypeProperties(ReflectionParameter $parameter): array
	{
		if (!$parameter->getType() instanceof ReflectionNamedType)
		{
			return [
				'type' => 'unknown',
			];
		}

		$result = $this->getPropertyTypeProperties($parameter->getType()->getName());

		if ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== null)
		{
			$result['example'] = $parameter->getDefaultValue();
		}

		return $result;
	}

	private function getPropertyTypeProperties(string $typeName): ?array
	{
		return match ($typeName)
		{
			'int' => [
				'type' => 'integer',
				'example' => 1,
			],
			'string' => [
				'type' => 'string',
				'example' => 'string',
			],
			'float' => [
				'type' => 'float',
				'example' => 1.0,
			],
			'bool' => [
				'type' => 'boolean',
				'example' => true,
			],
			'array' => [
				'type' => 'array',
			],
			default => null,
		};
	}

	private function getExamplesByDtoClass(\ReflectionProperty $property, ?DtoExample $dtoExample, ReflectionMethod $method): array
	{
		return match ($property->getName())
		{
			'id' => [
				'type' => 'integer',
				'example' => 1,
			],
			'cursor' => [
				'type' => 'object',
				'example' => [
					'field' => 'id',
					'value' => 0,
					'order' => 'ASC',
				],
			],
			'filter' => call_user_func(function () use ($method) {
				$result = [
					'type' => 'array',
				];
				if ($method->getName() !== 'tailAction')
				{
					$result['example'] = [['id', '>=', 1], ['id', 1], ['id', 'in', [1, 2, 3]]];
				}

				return $result;
			}),
			'select' => call_user_func(function () use ($dtoExample) {
				$result = [
					'type' => 'array',
					'items' => [
						'type' => 'string',
					],
				];
				if ($dtoExample !== null)
				{
					$result['example'] = $dtoExample->select;
				}

				return $result;
			}),
			'fields' => call_user_func(function () use ($method, $dtoExample) {
				$result = [
					'type' => 'object',
				];

				if ($dtoExample !== null)
				{
					$methodName = str_replace('Action', '', $method->getName());
					$result['properties'] = $method->getName() === 'updateAction' ? $dtoExample->editable : $dtoExample->addable;
					if (!empty($dtoExample->methodRequiredFields[$methodName]))
					{
						$result['required'] = $dtoExample->methodRequiredFields[$methodName];
					}
				}

				return $result;
			}),
			'order' => call_user_func(function () use ($dtoExample) {
				$result = [
					'type' => 'object',
				];
				if ($dtoExample !== null)
				{
					$result['properties'] = $dtoExample->sortable;
				}

				return $result;
			}),
			'pagination' => [
				'type' => 'object',
				'properties' => [
					'page' => ['type' => 'integer', 'example' => 2],
					'limit' => ['type' => 'integer', 'example' => 20],
					'offset' => ['type' => 'integer', 'example' => 0],
				],
			],
			'aggregate' => [
				'type' => 'object',
				'properties' => [
					'count' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
					'min' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
					'max' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
					'avg' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
					'sum' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
					'countDistinct' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['id']],
				],
			],
			default => call_user_func(function () use ($method, $property) {
				$propertyType = $this->getPropertyTypeProperties($property->getType()->getName());
				if ($propertyType === null)
				{
					return ['type' => 'unknown'];
				}

				if ($property->getType()->getName() === 'array' && !empty($property->getAttributes(ElementType::class)))
				{
					$elementType = $property->getAttributes(ElementType::class)[0]->newInstance()->type;
					$elementPropertyType = $this->getPropertyTypeProperties($elementType);
					if ($elementPropertyType !== null)
					{
						unset($elementPropertyType['example']);
						$propertyType['items'] = $elementPropertyType;
					}
				}

				return $propertyType;
			}),
		};
	}

	private function processTitleAttribute(object $attribute, array $data): array
	{
		$message = $this->getLocalizedMessage($attribute->value);

		return $message ? array_merge($data, ['summary' => $message]) : $data;
	}

	private function processDescriptionAttribute(object $attribute, array $data): array
	{
		$message = $this->getLocalizedMessage($attribute->value);

		return $message ? array_merge($data, ['description' => $message]) : $data;
	}

	private function getLocalizedMessage($value): ?string
	{
		if ($value instanceof LocalizableMessage)
		{
			return $this->language ? $value->localize($this->language) : (string)$value;
		}

		return $value !== null ? (string)$value : null;
	}

	/**
	 * @throws ReflectionException
	 */
	private function getMethodData(ReflectionMethod $method, string $returnTypeClass, ControllerData $controllerData): ?array
	{
		$methodData = [];
		foreach ($method->getAttributes() as $attribute)
		{
			$attributeName = $attribute->getName();
			$attributeInstance = $attribute->newInstance();

			$methodData = match ($attributeName)
			{
				Title::class => $this->processTitleAttribute($attributeInstance, $methodData),
				Description::class => $this->processDescriptionAttribute($attributeInstance, $methodData),
				Deprecated::class => array_merge($methodData, ['deprecated' => true]),
				default => $methodData,
			};
		}

		$methodData['tags'] = [$controllerData->module];

		$methodData['requestBody'] = [
			'content' => [
				'application/json' => [
					'schema' => $this->getMethodRequestSchema($method, $controllerData),
				],
			],
		];

		$methodData['responses'] = [
			200 => [
				'description' => 'Success response',
				'content' => [
					'application/json' => [
						'schema' => $this->getMethodResponseSchema($returnTypeClass, $controllerData->dto),
					],
				],
			],
		];

		return $methodData;
	}

	private function getDtoSchemaName(ReflectionClass $dto): string
	{
		return str_replace('\\', '', $dto->getName());
	}

	private function getPathUri(string $actionUri): string
	{
		if (!str_starts_with($actionUri, '/'))
		{
			$actionUri = '/' . $actionUri;
		}

		return $actionUri;
	}

	private function getMethodRequestSchema(ReflectionMethod $method, ControllerData $controllerData): array
	{
		$properties = $requestRequiredProperties = [];
		$parameters = $method->getParameters();

		foreach ($parameters as $parameter)
		{
			if ($parameter->hasType())
			{
				if (!$parameter->getType()->isBuiltin())
				{
					$requestTypeReflection = new ReflectionClass($parameter->getType()->getName());
					if ($requestTypeReflection->isSubclassOf(Request::class))
					{
						$dto = $controllerData->dto?->getName()::create();
						foreach ($requestTypeReflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property)
						{
							$dtoExample = $this->getDtoExample($dto);

							$properties[$property->getName()] = $this->getExamplesByDtoClass($property, $dtoExample, $method);

							if (!$property->getType()->allowsNull())
							{
								$requestRequiredProperties[] = $property->getName();
							}
						}
					}
				}
				else
				{
					$properties[$parameter->getName()] = $this->getRequestTypeProperties($parameter);
					if (!$parameter->getType()->allowsNull())
					{
						$requestRequiredProperties[] = $parameter->getName();
					}
				}
			}
		}

		$baseRequestSchema = function (array $properties, array $required = []) {
			$data = [
				'type' => 'object',
				'properties' => $properties,
			];
			if ($required)
			{
				$data['required'] = $required;
			}

			return $data;
		};

		return $baseRequestSchema($properties, $requestRequiredProperties);
	}

	private function getDtoExample(?Dto $dto = null): ?DtoExample
	{
		if ($dto === null)
		{
			return null;
		}
		if (!isset(self::$dtoExamples[get_class($dto)]))
		{
			$select = $sortable = $addable = $editable = $methodRequiredFields = [];

			/** @var DtoField $dtoField */
			foreach ($dto->getFields() as $dtoField)
			{
				if ($dtoField->getRelation() !== null)
				{
					continue;
				}
				$select[] = $dtoField->getPropertyName();

				if ($dtoField->isSortable())
				{
					$sortable[$dtoField->getPropertyName()] = [
						'type' => 'string',
						'example' => 'ASC',
					];
				}

				if ($dtoField->getRequiredGroups() !== null)
				{
					foreach ($dtoField->getRequiredGroups() as $methodName)
					{
						$methodRequiredFields[$methodName][] = $dtoField->getPropertyName();
					}
				}


				$addable[$dtoField->getPropertyName()] = $this->getFormatByType($dtoField);
				if ($dtoField->isEditable())
				{
					$editable[$dtoField->getPropertyName()] = $addable[$dtoField->getPropertyName()];
				}
			}

			self::$dtoExamples[get_class($dto)] = new DtoExample(
				get_class($dto),
				$select,
				$addable,
				$editable,
				$sortable,
				$methodRequiredFields,
			);
		}

		return self::$dtoExamples[get_class($dto)];
	}
}

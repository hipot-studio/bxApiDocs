<?php

namespace Bitrix\Rest\V3\Mcp\Schema;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Schema\MethodDescription;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class ToolSchemaBuilder
{
	public function build(MethodDescription $methodDescription): array
	{
		$controllerReflection = $this->getControllerReflection($methodDescription->controllerFqcn);
		if ($controllerReflection === null)
		{
			return ['type' => 'object'];
		}

		$actionMethodName = $methodDescription->method . 'Action';
		if (!$controllerReflection->hasMethod($actionMethodName))
		{
			return ['type' => 'object'];
		}

		$actionMethod = $controllerReflection->getMethod($actionMethodName);
		$properties = [];
		$required = [];
		foreach ($actionMethod->getParameters() as $parameter)
		{
			$parameterType = $parameter->getType();
			if (!$parameterType instanceof ReflectionNamedType)
			{
				continue;
			}

			$typeName = $parameterType->getName();
			if ($parameterType->isBuiltin())
			{
				$properties[$parameter->getName()] = $this->getPrimitiveSchema($typeName);
				if (!$parameter->isDefaultValueAvailable())
				{
					$required[] = $parameter->getName();
				}

				continue;
			}

			if (!is_subclass_of($typeName, Request::class))
			{
				continue;
			}

			$this->appendRequestProperties($properties, $required, $typeName, $methodDescription);
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties,
		];

		if ($required !== [])
		{
			$schema['required'] = array_values(array_unique($required));
		}

		return $schema;
	}

	private function appendRequestProperties(
		array &$properties,
		array &$required,
		string $requestClass,
		MethodDescription $methodDescription,
	): void
	{
		$dto = $this->getDto($methodDescription);
		$availableFieldNames = $this->getAvailableFieldNames($dto, $methodDescription);

		$requestReflection = new ReflectionClass($requestClass);
		foreach ($requestReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
		{
			if ($property->getAttributes(Hidden::class) !== [])
			{
				continue;
			}

			$propertyType = $property->getType();
			if (!$propertyType instanceof ReflectionNamedType)
			{
				continue;
			}

			$propertyName = $property->getName();
			$properties[$propertyName] = match ($propertyName)
			{
				'select' => $this->buildSelectSchema($availableFieldNames),
				'filter' => $this->buildFilterSchema(),
				'order' => $this->buildOrderSchema($availableFieldNames),
				'pagination' => $this->buildPaginationSchema(),
				'cursor' => $this->buildCursorSchema($availableFieldNames),
				'aggregate' => $this->buildAggregateSchema($availableFieldNames),
				'fields' => $this->buildFieldsSchema($dto, $methodDescription),
				default => $this->getPrimitiveSchema($propertyType->getName()),
			};

			if (!$property->hasDefaultValue())
			{
				$required[] = $propertyName;
			}
		}
	}

	private function buildSelectSchema(array $availableFieldNames): array
	{
		// nested paths of related entities are also accepted, so content validation stays in SelectStructure
		$schema = [
			'type' => 'array',
			'items' => ['type' => 'string'],
		];

		if ($availableFieldNames !== [])
		{
			// only own fields are offered: the bridge does not run the subqueries a nested path would need
			$schema['description'] = 'Allowed fields: ' . implode(', ', $availableFieldNames);
		}

		return $schema;
	}

	private function buildFilterSchema(): array
	{
		// a filter is either a single condition or a list of them, so content validation stays in FilterStructure
		return ['type' => 'array'];
	}

	private function buildOrderSchema(array $availableFieldNames): array
	{
		$schema = [
			'type' => 'object',
			'additionalProperties' => [
				'type' => 'string',
				'enum' => ['ASC', 'DESC'],
			],
		];

		if ($availableFieldNames !== [])
		{
			$schema['description'] = 'Allowed fields: ' . implode(', ', $availableFieldNames);
		}

		return $schema;
	}

	private function buildPaginationSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page' => ['type' => 'integer'],
				'limit' => ['type' => 'integer'],
				'offset' => ['type' => 'integer'],
			],
		];
	}

	private function buildCursorSchema(array $availableFieldNames): array
	{
		$schema = [
			'type' => 'object',
			'properties' => [
				'field' => ['type' => 'string'],
				'value' => ['type' => 'string'],
				'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit' => ['type' => 'integer'],
			],
			'required' => ['field', 'value'],
		];

		if ($availableFieldNames !== [])
		{
			$schema['properties']['field']['enum'] = $availableFieldNames;
		}

		return $schema;
	}

	private function buildAggregateSchema(array $availableFieldNames): array
	{
		$items = ['type' => 'string'];
		if ($availableFieldNames !== [])
		{
			$items['enum'] = $availableFieldNames;
		}

		return [
			'type' => 'object',
			'properties' => [
				'count' => ['type' => 'array', 'items' => $items],
				'min' => ['type' => 'array', 'items' => $items],
				'max' => ['type' => 'array', 'items' => $items],
				'avg' => ['type' => 'array', 'items' => $items],
				'sum' => ['type' => 'array', 'items' => $items],
				'countDistinct' => ['type' => 'array', 'items' => $items],
			],
		];
	}

	private function buildFieldsSchema(?Dto $dto, MethodDescription $methodDescription): array
	{
		if ($dto === null)
		{
			return ['type' => 'object'];
		}

		$properties = [];
		$required = [];
		foreach ($dto->getFields() as $field)
		{
			if (!$this->isFieldAvailable($field, $methodDescription))
			{
				continue;
			}

			if (
				$field->getEditableGroups() === null
				|| !(
					$field->getEditableGroups() === []
					|| in_array($methodDescription->method, $field->getEditableGroups(), true)
				)
			)
			{
				continue;
			}

			$properties[$field->getPropertyName()] = $this->getFieldSchema($field);
			if ($field->getRequiredGroups() !== null && (
				$field->getRequiredGroups() === []
				|| in_array($methodDescription->method, $field->getRequiredGroups(), true)
			))
			{
				$required[] = $field->getPropertyName();
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties,
			// the validator rejects unknown keys, so a field excluded from the export cannot reach the controller
			'additionalProperties' => false,
		];

		if ($required !== [])
		{
			$schema['required'] = $required;
		}

		return $schema;
	}

	private function getPrimitiveSchema(string $typeName): array
	{
		return match ($typeName)
		{
			'mixed' => [],
			'int' => ['type' => 'integer'],
			'float' => ['type' => 'number'],
			'bool' => ['type' => 'boolean'],
			'array' => ['type' => 'array'],
			default => ['type' => 'string'],
		};
	}

	private function getFieldSchema(DtoField $field): array
	{
		$isDtoCollectionField = $this->isDtoCollectionField($field);
		if ($isDtoCollectionField)
		{
			$schema = [
				'type' => 'array',
				'items' => ['type' => 'object'],
			];
		}
		elseif ($field->getPropertyType() === 'array')
		{
			$schema = ['type' => 'array'];
			$items = $this->getArrayItemsSchema($field->getElementType());
			if ($items !== null)
			{
				$schema['items'] = $items;
			}
		}
		else
		{
			$schema = $this->isDtoType($field->getPropertyType())
				? ['type' => 'object']
				: $this->getPrimitiveSchema($field->getPropertyType());
		}

		if ($field->isMultiple() && !$isDtoCollectionField)
		{
			$schema = [
				'type' => 'array',
				'items' => $schema,
			];
		}

		$description = $this->getFieldDescription($field);
		if ($description !== null)
		{
			$schema['description'] = $description;
		}

		return $schema;
	}

	private function getArrayItemsSchema(?string $elementType): ?array
	{
		return match ($elementType)
		{
			'int' => ['type' => 'integer'],
			'string' => ['type' => 'string'],
			'bool' => ['type' => 'boolean'],
			'float' => ['type' => 'number'],
			default => null,
		};
	}

	private function getFieldDescription(DtoField $field): ?string
	{
		$description = $this->localizeFieldText($field->getDescription());
		if ($description !== '')
		{
			return $description;
		}

		$title = $this->localizeFieldText($field->getTitle());
		if ($title !== '' && $title !== $field->getPropertyName())
		{
			return $title;
		}

		return null;
	}

	private function localizeFieldText(LocalizableMessage|string|null $text): string
	{
		if ($text instanceof LocalizableMessage)
		{
			$text = $text->localize('en');
		}

		return trim((string)$text);
	}

	private function isDtoCollectionField(DtoField $field): bool
	{
		if (!$this->isDtoType($field->getElementType()))
		{
			return false;
		}

		$propertyType = $field->getPropertyType();

		return $propertyType === DtoCollection::class
			|| TypeHelper::isSubclassOf($propertyType, DtoCollection::class);
	}

	/**
	 * Scalars wrapped into objects, such as DateTime or enums, are not Dto and keep their primitive schema.
	 */
	private function isDtoType(?string $typeName): bool
	{
		return TypeHelper::isDto($typeName);
	}

	private function getDto(MethodDescription $methodDescription): ?Dto
	{
		$dtoClass = $methodDescription->dtoFqcn;
		if (!TypeHelper::isDto($dtoClass))
		{
			return null;
		}

		return $dtoClass::create();
	}

	private function getAvailableFieldNames(?Dto $dto, MethodDescription $methodDescription): array
	{
		if ($dto === null)
		{
			return [];
		}

		$fieldNames = [];
		foreach ($dto->getFields() as $field)
		{
			if ($this->isFieldAvailable($field, $methodDescription))
			{
				$fieldNames[] = $field->getPropertyName();
			}
		}

		return $fieldNames;
	}

	private function isFieldAvailable(DtoField $field, MethodDescription $methodDescription): bool
	{
		$mcpTool = $methodDescription->mcpTool;

		return $mcpTool === null || $mcpTool->isFieldAllowed($field->getPropertyName());
	}

	private function getControllerReflection(?string $controllerFqcn): ?ReflectionClass
	{
		if ($controllerFqcn === null || !class_exists($controllerFqcn))
		{
			return null;
		}

		return new ReflectionClass($controllerFqcn);
	}
}

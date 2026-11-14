<?php

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Rest\V3\Access\GrantFieldsGuard;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Exception\InvalidJsonException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Relation;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationSelectStructure;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\ReferencesDtoFields;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Rest\V3\Structure\Structure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

abstract class Request
{
	private const MAX_EXACT_FLOAT_INTEGER = 2 ** 53;

	/**
	 * @var Relation[]
	 */
	protected array $relations = [];

	public function __construct(protected string $dtoClass, protected array $options = [])
	{

	}

	public function getRelations(): array
	{
		return $this->relations;
	}

	public function getRelation(string $relationName): ?Relation
	{
		return $this->relations[$relationName] ?? null;
	}

	public function addRelation(Relation $relation): void
	{
		$this->relations[$relation->getName()] = $relation;
	}

	/**
	 * @param HttpRequest $httpRequest
	 * @param string $dtoClass
	 * @param array{scope?: ?Scope, action?: ?string} $options
	 * @return Request
	 * @throws InvalidJsonException
	 * @throws RequiredFieldInRequestException
	 * @throws UnknownDtoPropertyException
	 * @throws SystemException
	 * @see Dto
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		$request = new static($dtoClass, $options);

		/** @var Dto $dto */
		$dto = $dtoClass::create();
		Structure::addDto($dto);

		// input data
		try
		{
			// php://input belongs to the outer request, not to one built in-process with its own JSON data
			if (empty($httpRequest->getJsonList()->getValues()) && !empty($httpRequest::getInput()))
			{
				$httpRequest->decodeJsonStrict();
			}

			$input = $httpRequest->getJsonList()->getValues();
		}
		catch (SystemException)
		{
			throw new InvalidJsonException();
		}

		// properties of request
		$reflection = new ReflectionClass($request);

		$properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

		$scope = $options['scope'] ?? null;
		$scopeFields = ($scope instanceof Scope) ? $scope->fields : [];
		$grantFieldsGuard = !empty($scopeFields) ? new GrantFieldsGuard($scopeFields, $dto->getShortName()) : null;

		// set input data into the request
		foreach ($properties as $property)
		{
			if (!$property->getType() instanceof ReflectionNamedType)
			{
				continue;
			}

			$propertyName = $property->getName();
			$propertyType = $property->getType()->getName();
			$isOptional = $property->getType()->allowsNull();
			$hasDefaultValue = $property->hasDefaultValue();

			// an empty select must not lift the field restriction of the scope
			if (
				$propertyName === 'select'
				&& !empty($scopeFields)
				&& TypeHelper::isSubclassOf($propertyType, Structure::class)
				&& !is_a($propertyType, AggregationSelectStructure::class, true)
				&& isset($input[$propertyName])
				&& $input[$propertyName] === []
			)
			{
				unset($input[$propertyName]);
			}

			if (!isset($input[$propertyName]))
			{
				if (!$isOptional && !$hasDefaultValue)
				{
					// field not found, but it is required
					throw new RequiredFieldInRequestException($propertyName);
				}

				if ($hasDefaultValue)
				{
					$input[$propertyName] = $property->getDefaultValue();
				}
				if ($propertyName === 'select' && !empty($scopeFields))
				{
					$input[$propertyName] = $scopeFields;
				}
				else
				{
					continue;
				}
			}

			if (TypeHelper::isSubclassOf($propertyType, Structure::class))
			{
				// the grant is checked on the raw input, so a hidden field fails before its type is validated
				if ($grantFieldsGuard !== null && is_subclass_of($propertyType, ReferencesDtoFields::class))
				{
					$grantFieldsGuard->assertAllowed(
						$propertyType::getReferencedFields($input[$propertyName]),
						self::getGrantExemptFields($property, $propertyType, $dto, $options['action'] ?? null),
					);
				}

				// validate with Dto
				$value = $propertyType::create($input[$propertyName], $dtoClass, $request);
			}
			else
			{
				$value = $input[$propertyName];
			}

			// php would silently truncate 42.9 to 42 and turn true into 1
			if ($propertyType === 'int' && !self::isIntegerValue($value))
			{
				throw new InvalidRequestFieldTypeException($propertyName, $propertyType);
			}

			try
			{
				$request->{$propertyName} = $value;
			}
			catch (\TypeError $exception)
			{
				throw new InvalidRequestFieldTypeException($propertyName, $propertyType);
			}

			if (isset($request->{$propertyName}) && $request->{$propertyName} instanceof FilterStructure)
			{
				$propertyReflection = new \ReflectionProperty($request, $propertyName);
				$requiredFilterAttributes = $propertyReflection->getAttributes(FilterRequired::class);
				if (!empty($requiredFilterAttributes))
				{
					$requiredFilterFields =	$requiredFilterAttributes[0]->newInstance()->fields;
					$usedFields = array_keys($request->{$propertyName}->getSimpleFilterConditions());
					$missingFields = array_diff($requiredFilterFields, $usedFields);
					$containsAllRequiredFields = ($missingFields === []);

					if (!$containsAllRequiredFields)
					{
						throw new RequiredFieldsInRequestFilterPropertyException($propertyName, $missingFields);
					}
				}
			}
		}

		$validationService = ServiceLocator::getInstance()->get(ValidationService::class);
		$validationResult = $validationService->validate($request);

		if (!$validationResult->isSuccess())
		{
			throw new RequestValidationException($validationResult->getErrors());
		}

		return $request;
	}

	/**
	 * Fields the client may name outside the grant: access to the method implies access to the fields
	 * the method demands, those of #[FilterRequired] in the filter and those of #[Required] in the fields.
	 * Reading is also allowed by the fields the select returns under any grant.
	 *
	 * @return list<string>
	 */
	private static function getGrantExemptFields(
		ReflectionProperty $property,
		string $propertyType,
		Dto $dto,
		?string $action,
	): array
	{
		$filterRequiredAttributes = $property->getAttributes(FilterRequired::class);
		if (!empty($filterRequiredAttributes))
		{
			return array_merge(
				SelectStructure::getGrantFreeFields($dto),
				$filterRequiredAttributes[0]->newInstance()->fields,
			);
		}

		if (!is_a($propertyType, FieldsStructure::class, true))
		{
			return SelectStructure::getGrantFreeFields($dto);
		}

		$exemptFields = [];

		foreach ($dto->getFields()->getMetaList() as $field)
		{
			$requiredGroups = $field->getRequiredGroups();
			if ($requiredGroups === [] || ($action !== null && in_array($action, $requiredGroups ?? [], true)))
			{
				$exemptFields[] = $field->getPropertyName();
			}
		}

		return $exemptFields;
	}

	private static function isIntegerValue(mixed $value): bool
	{
		if (is_bool($value))
		{
			return false;
		}

		if (is_string($value))
		{
			// any other numeric notation goes through float and may round to a neighbouring integer
			return !is_numeric($value) || preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1;
		}

		if (is_float($value))
		{
			// beyond 2^53 a float no longer identifies a single integer
			return is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_EXACT_FLOAT_INTEGER;
		}

		return true;
	}

	public function getDtoClass(): string
	{
		return $this->dtoClass;
	}

	public function getOptions(): array
	{
		return $this->options;
	}
}

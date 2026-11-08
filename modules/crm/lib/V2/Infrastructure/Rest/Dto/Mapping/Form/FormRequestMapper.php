<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\UpdateFormRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FormListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormFilter;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormListSort;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSort;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsConverter;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;

final class FormRequestMapper
{
	private const FIELD_UPDATED_TIME = 'updatedTime';

	private const DEFAULT_SCALAR_FIELDS = [
		WebForm::FIELD_ID,
		WebForm::FIELD_CODE,
		WebForm::FIELD_NAME,
		WebForm::FIELD_ACTIVE,
		WebForm::FIELD_SYSTEM,
		WebForm::FIELD_LANGUAGE_ID,
		WebForm::FIELD_TEMPLATE_ID,
		WebForm::FIELD_CREATED_TIME,
		self::FIELD_UPDATED_TIME,
		WebForm::FIELD_ACTIVE_CHANGED_BY_ID,
		WebForm::FIELD_ACTIVE_CHANGED_TIME,
	];

	/**
	 * Maps the transport filter to the web form filter.
	 *
	 * A condition naming a field of a section keeps its name as it is: the domain is given
	 * `crm.entityScheme` under the very path the answer publishes the field by, so the field is named
	 * one way from the request through to the map of the storage. The version of the form is the one
	 * exception, and the same one the projection has: it reaches the domain under its domain name
	 * ({@see self::toDomainField()}).
	 *
	 * @throws InvalidFilterException
	 */
	public function mapFilter(?FormListFilterStructure $filter): WebFormFilter
	{
		return $filter === null ? WebFormFilter::empty() : self::mapFilterGroup($filter);
	}

	/**
	 * Maps the transport order of the form list to the complete order of the storage.
	 *
	 * The keys reach the domain in the order the request wrote them, under the names the domain knows
	 * the fields by ({@see self::toDomainField()}), and the identifier of the form is written as the
	 * last of them - see {@see self::completeWithTheIdentifier()} for why the order is never left
	 * partial.
	 *
	 * @throws InvalidOrderException
	 */
	public function mapOrder(?FormListOrderStructure $order): WebFormListSort
	{
		$requestedKeys = $order?->getList() ?? [];
		$domainKeys = [];
		foreach ($requestedKeys as $field => $direction)
		{
			$domainKeys[self::toDomainField((string)$field)] = $direction;
		}

		try
		{
			return new WebFormListSort(self::completeWithTheIdentifier($domainKeys));
		}
		catch (ArgumentException)
		{
			throw new InvalidOrderException($requestedKeys);
		}
	}

	/**
	 * Maps the transport order of the neighbouring lists of the family - the widgets and the open lines
	 * of a form - to their order. Those are ordered by the identifier alone, and asking them for
	 * anything else is refused here rather than answered by an order they do not apply.
	 *
	 * @throws InvalidOrderException
	 */
	public function mapIdOrder(?ItemListOrderStructure $order): WebFormSort
	{
		$orderList = $order?->getList() ?? [];
		if ($orderList === [])
		{
			return new WebFormSort();
		}

		$direction = $orderList[WebForm::FIELD_ID] ?? null;
		if (count($orderList) !== 1 || !is_string($direction))
		{
			throw new InvalidOrderException($orderList);
		}

		try
		{
			return new WebFormSort($direction);
		}
		catch (ArgumentException)
		{
			throw new InvalidOrderException($orderList);
		}
	}

	/**
	 * Maps the transport pagination to the bounded page.
	 *
	 * @throws InvalidPaginationException
	 */
	public function mapPager(?FormListPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}

	/**
	 * Maps the transport projection to the web form read projection.
	 */
	public function mapSelect(Request $request): WebFormSelect
	{
		$paths = array_map(
			static fn(string $path): string => self::toDomainField($path),
			$this->getSelectedPaths($request),
		);

		try
		{
			return WebFormSelect::fromPaths($paths);
		}
		catch (ArgumentException)
		{
			throw new InvalidSelectException($paths);
		}
	}

	/**
	 * Returns the same projection in the structure expected by the response DTO mapper.
	 *
	 * @return array<int|string, string|string[]>
	 */
	public function mapStructuredFields(WebFormSelect $select): array
	{
		$fields = $select->getBaseFields();
		foreach ($select->getSectionFieldsBySection() as $section => $sectionFields)
		{
			$fields[$section] = [];
			foreach ($sectionFields as $fieldPath)
			{
				$fieldName = explode('.', $fieldPath, 2)[0];
				if (!in_array($fieldName, $fields[$section], true))
				{
					$fields[$section][] = $fieldName;
				}
			}
		}

		return $fields;
	}

	/**
	 * The version of the form a write expects to write over, as every write of the family carries
	 * it: {@see UpdateFormRequest::$updatedTime} and the same property of the write of the
	 * embedding.
	 *
	 * The value arrives as the string the transport delivered, read here with the same converter
	 * REST V3 uses for a typed date of a DTO. A value it does not read as a date is refused with
	 * the very refusal the framework raises for a field of the wrong type, so the answer stays the
	 * one the published contract documents no matter which side did the reading.
	 *
	 * @throws InvalidRequestFieldTypeException
	 */
	public function mapExpectedVersion(string $updatedTime): DateTime
	{
		$expectedVersion = FieldsConverter::convertValueByType(DateTime::class, $updatedTime);
		if (!$expectedVersion instanceof DateTime)
		{
			throw new InvalidRequestFieldTypeException(self::FIELD_UPDATED_TIME, DateTime::class);
		}

		return $expectedVersion;
	}

	/**
	 * The order with the identifier of the form as its last key - the key that makes any order
	 * complete, so that a page cut by a limit and an offset cannot lose or repeat a form whose ordered
	 * value it shares with another.
	 *
	 * An order that already names the identifier is complete as it is and is left alone: appending the
	 * key again would overwrite the direction the request asked for. The direction of the written key
	 * is fixed rather than inherited from the last requested one - any fixed direction makes the order
	 * complete, and a fixed one is easier to explain.
	 *
	 * @param array<string, string> $keys
	 * @return array<string, string>
	 */
	private static function completeWithTheIdentifier(array $keys): array
	{
		if ($keys === [])
		{
			return [WebForm::FIELD_ID => WebFormListSort::ASC];
		}

		if (array_key_exists(WebForm::FIELD_ID, $keys))
		{
			return $keys;
		}

		$keys[WebForm::FIELD_ID] = WebFormListSort::ASC;

		return $keys;
	}

	/**
	 * The name the domain knows the field by. Only the version of the form differs: the answer
	 * publishes it as the date the form was updated at, the domain and the storage call it by their
	 * own name, and the substitution is one and the same for the projection and for the filter.
	 */
	private static function toDomainField(string $field): string
	{
		return $field === self::FIELD_UPDATED_TIME ? WebForm::FIELD_MODIFIED_TIME : $field;
	}

	/**
	 * @return string[]
	 */
	private function getSelectedPaths(Request $request): array
	{
		$select = $request->select ?? null;
		if ($select !== null && !$select instanceof SelectStructure)
		{
			throw new InvalidSelectException($select);
		}

		$structuredFields = $select?->getStructuredList() ?? [];
		$paths = [];
		foreach ($structuredFields as $section => $sectionFields)
		{
			if (is_int($section))
			{
				$paths[] = $sectionFields;

				continue;
			}

			if ($select->isFullRelationSelected($section))
			{
				$paths[] = $section;

				continue;
			}

			foreach ($sectionFields as $fieldName)
			{
				$paths[] = $section . '.' . $fieldName;
			}
		}

		if ($select !== null)
		{
			foreach ($select->getRelationFields() as $relationField)
			{
				if (
					$select->isFullRelationSelected($relationField)
					&& !in_array($relationField, $paths, true)
				)
				{
					$paths[] = $relationField;
				}
			}
		}

		return $paths === [] ? self::DEFAULT_SCALAR_FIELDS : $paths;
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function mapFilterGroup(FormListFilterStructure $filter): WebFormFilter
	{
		$conditions = [];
		foreach ($filter->getConditions() as $condition)
		{
			$conditions[] = match (true)
			{
				$condition instanceof FormListFilterStructure => self::mapFilterGroup($condition),
				$condition instanceof Condition => self::mapFilterCondition($condition),
				default => throw new InvalidFilterException($condition),
			};
		}

		try
		{
			return WebFormFilter::group(
				$filter->logic()->value,
				$filter->isNegative(),
				$conditions,
			);
		}
		catch (ArgumentException)
		{
			throw new InvalidFilterException($filter->getList());
		}
	}

	/**
	 * An enumeration naming no value reaches the domain as the request wrote it: the predicate that
	 * matches nothing is built where the statement is
	 * ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository}).
	 *
	 * @throws InvalidFilterException
	 */
	private static function mapFilterCondition(Condition $condition): WebFormFilter
	{
		$field = $condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (!is_string($field) || $value instanceof Expression)
		{
			throw new InvalidFilterException([$field, $operator->value, $value]);
		}

		try
		{
			return WebFormFilter::condition(self::toDomainField($field), $operator->value, $value);
		}
		catch (ArgumentException)
		{
			throw new InvalidFilterException([$field, $operator->value, $value]);
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterValidator;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListFormRequest}: the filter of
 * the transport, plus the two things a page of CRM forms is narrowed by that the transport cannot read
 * on its own.
 *
 * {@see FilterStructure} resolves the name of a condition as a field of the top level of the contract,
 * so a form is narrowed by its target entity or by its responsible - both of which the answer carries
 * inside a section - only if the path of the section may be named. This structure adds exactly that,
 * and nothing else:
 *
 * 1. {@see self::FILTERABLE_PATHS} - the closed list of paths of sections a filter may name. A path
 *    outside it is left to the transport, which refuses it as an unknown property of the contract, the
 *    same refusal it answers today.
 * 2. {@see self::IDENTIFIER_COLLECTION_PATHS} - the paths whose value is a user rather than a value of
 *    the field. `responsible.userIds` is read as one identifier or a list of them, and only a positive
 *    one: the answer builds the section out of two sources and drops every identifier that is not
 *    positive ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository}), so a filter that
 *    took a zero would narrow by something the answer never shows.
 *
 * Everything else is the transport's: a name of the top level, a group, an operator, an empty list of
 * values, an expression as an operand and the type of a value are all decided by
 * {@see FilterStructure}, and a filter that names no path of a section is handed to it whole - only
 * the class of its nodes is exchanged for this one ({@see self::adopt()}). That is what keeps the
 * refusals of this method, their classes and their messages the ones it answered before the paths were
 * opened.
 *
 * The nodes read here are read by {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper}
 * the way the nodes of the transport are, so the names of the reading methods are the ones that class
 * has: the mapper walks a tree, not two.
 *
 * {@see \Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired} cannot be used on a property
 * holding this structure: {@see Request::create()} honours the attribute only for a
 * {@see FilterStructure}. The list of forms requires no condition, so nothing is lost; a group of
 * methods that does require one checks it where the neighbouring filters of the module do - in the
 * structure itself.
 *
 * The budget of the request ({@see self::requireWithinBudget()}) is held against the tree the client
 * sent rather than against the nodes it is read into, which is what lets a client count it: a
 * condition by the responsible costs the statement a whole pass over the queue of the responsible,
 * and the number of such conditions is the number of such passes.
 */
final class FormListFilterStructure extends Structure
{
	/**
	 * How deep a filter may nest its nodes. The filter a client can write today reaches three levels -
	 * a group of groups of conditions - so two more are left over it.
	 */
	public const MAX_DEPTH = 5;

	/**
	 * How many nodes - conditions and the groups they stand in - one filter may name. A flat group of
	 * conditions costs one node over their number, so up to forty-nine conditions are answered - and
	 * that is also the ceiling of the number of passes over the queue a filter by the responsible can
	 * cost.
	 */
	public const MAX_NODES = 50;

	/**
	 * How many values the enumerations of one filter may name, all of them together and each counted
	 * once. A filter that enumerates a whole page of forms is answered.
	 */
	public const MAX_IN_ITEMS = 1000;

	/**
	 * @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\CrmSettingsDto::$entityScheme
	 * @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\ResponsibleDto::$userIds
	 */
	private const FILTERABLE_PATHS = [
		'crm.entityScheme',
		'responsible.userIds',
	];

	private const IDENTIFIER_COLLECTION_PATHS = [
		'responsible.userIds',
	];

	private const NODE_TYPE_FILTER = 'filter';

	/** @var array<Condition|self> */
	private array $conditions = [];

	private Logic $logic = Logic::And;

	private bool $isNegative = false;

	private array $rawData = [];

	private function __construct()
	{
	}

	/**
	 * @throws InvalidFilterException also when the filter is outside the budget of a request
	 * @throws DtoFieldRequiredAttributeException a path of a section that the contract publishes
	 *         without {@see Filterable}
	 * @throws InvalidRequestFieldTypeException a user that is not a positive identifier
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		// The transport reads the contract from the registry of structures rather than building it.
		self::requireDto($dtoClass);
		$node = (array)$value;
		self::requireWithinBudget($node);

		return self::readNode($node, $dtoClass, $request);
	}

	/**
	 * @return array<Condition|self>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function logic(): Logic
	{
		return $this->logic;
	}

	public function isNegative(): bool
	{
		return $this->isNegative;
	}

	/** The node of the request this one was read from, as the transport received it. */
	public function getList(): array
	{
		return $this->rawData;
	}

	/**
	 * The filter is measured before it is read, so a filter outside the budget costs the portal the
	 * measurement alone rather than the whole reading of the tree and the statement built out of it.
	 *
	 * Nothing is refused here for the shape of a node: a malformed node is left to the reading, which
	 * answers it with the classes of refusal it answered before the budget existed. Only the counts
	 * are answered here.
	 *
	 * A node that names nothing at all is counted as any other: the reading walks it too and reaches
	 * a structure of its own for every one of them, so the number of nodes is the number the client
	 * sent - which is what the bound is held against.
	 *
	 * @throws InvalidFilterException the filter nests its nodes deeper, names more of them or
	 *         enumerates more values than one request may
	 */
	private static function requireWithinBudget(array $node): void
	{
		$budget = ['nodes' => 0, 'inItems' => 0];
		self::measureNode($node, 1, $budget);
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function measureNode(array $node, int $depth, array &$budget): void
	{
		if ($depth > self::MAX_DEPTH)
		{
			throw new InvalidFilterException('Filter depth limit exceeded.');
		}

		$budget['nodes']++;
		if ($budget['nodes'] > self::MAX_NODES)
		{
			throw new InvalidFilterException('Filter node limit exceeded.');
		}

		$children = self::childrenOf($node);
		if ($children === null)
		{
			self::measureEnumeration($node, $budget);

			return;
		}

		foreach ($children as $child)
		{
			if (is_array($child))
			{
				self::measureNode($child, $depth + 1, $budget);
			}
		}
	}

	/**
	 * The nodes standing inside this one, or `null` when the node is a condition of its own. The two
	 * shapes that carry children are the two the reading knows: a group and a list of nodes.
	 */
	private static function childrenOf(array $node): ?array
	{
		if (self::isGroup($node))
		{
			return is_array($node['conditions'] ?? null) ? $node['conditions'] : [];
		}

		return self::isListOfNodes($node) ? $node : null;
	}

	/**
	 * The values of a condition that carries a list of them. Repeats are not counted: the storage
	 * builds one value of an enumeration out of them, so a repeat costs the statement nothing
	 * ({@see \Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository}).
	 *
	 * @throws InvalidFilterException
	 */
	private static function measureEnumeration(array $condition, array &$budget): void
	{
		$value = match (count($condition))
		{
			2 => $condition[1] ?? null,
			3 => $condition[2] ?? null,
			default => null,
		};
		if (!is_array($value))
		{
			return;
		}

		$budget['inItems'] += self::countUniqueValues($value);
		if ($budget['inItems'] > self::MAX_IN_ITEMS)
		{
			throw new InvalidFilterException('Filter collection operand limit exceeded.');
		}
	}

	/**
	 * Values of different types are counted apart: whether a number and its decimal notation are one
	 * value is decided by the field they are compared against, and the budget decides nothing about
	 * the meaning of a value.
	 */
	private static function countUniqueValues(array $values): int
	{
		$seen = [];
		foreach ($values as $value)
		{
			$seen[get_debug_type($value) . ':' . serialize($value)] = true;
		}

		return count($seen);
	}

	/**
	 * A node that names no path of a section is the transport's own: it is read by
	 * {@see FilterStructure} and only re-clothed here.
	 */
	private static function readNode(array $node, string $dtoClass, Request $request): self
	{
		if (!self::namesFilterablePath($node))
		{
			return self::adopt(FilterStructure::create($node, $dtoClass, $request));
		}

		return self::isGroup($node)
			? self::readGroup($node, $dtoClass, $request)
			: self::readConditionNode($node, $dtoClass, $request)
		;
	}

	private static function isGroup(array $node): bool
	{
		return isset($node['type'])
			|| isset($node['logic'])
			|| isset($node['conditions'])
			|| isset($node['negative']);
	}

	/**
	 * The shape the transport wraps a filter of a body into, and one a client may write too: a list
	 * whose items are nodes rather than the operands of a condition. An operand that is an expression
	 * is an array as well, which is what the second half tells apart.
	 */
	private static function isListOfNodes(array $node): bool
	{
		return is_array($node[0] ?? null) && !isset($node[0]['expression']);
	}

	/**
	 * The logic of the group, its negation and the refusal of a malformed group stay with
	 * {@see FilterStructure}: it reads the group without its children, and the children are read here.
	 */
	private static function readGroup(array $node, string $dtoClass, Request $request): self
	{
		$children = $node['conditions'] ?? null;
		if (!is_array($children))
		{
			return self::adopt(FilterStructure::create($node, $dtoClass, $request));
		}

		$childlessNode = $node;
		$childlessNode['conditions'] = [];

		$structure = self::adopt(FilterStructure::create($childlessNode, $dtoClass, $request));
		$structure->rawData = $node;
		foreach ($children as $child)
		{
			$structure->conditions[] = self::readChild($child, $dtoClass, $request);
		}

		return $structure;
	}

	/**
	 * @throws InvalidFilterException a child that is neither a node of conditions nor a group naming
	 *         itself with `type: filter`, which is what {@see FilterStructure} accepts inside a group.
	 */
	private static function readChild(mixed $child, string $dtoClass, Request $request): self
	{
		if (!is_array($child))
		{
			throw new InvalidFilterException($child);
		}

		if (self::isGroup($child) && ($child['type'] ?? null) !== self::NODE_TYPE_FILTER)
		{
			throw new InvalidFilterException($child);
		}

		return self::readNode($child, $dtoClass, $request);
	}

	/** A condition of its own, or a list of nodes - the shape the transport wraps a filter into. */
	private static function readConditionNode(array $node, string $dtoClass, Request $request): self
	{
		$structure = new self();
		$structure->rawData = $node;

		if (self::isListOfNodes($node))
		{
			foreach ($node as $child)
			{
				$structure->conditions[] = self::readChild($child, $dtoClass, $request);
			}

			return $structure;
		}

		$structure->conditions[] = self::readCondition($node, $dtoClass, $request);

		return $structure;
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function readCondition(array $condition, string $dtoClass, Request $request): Condition
	{
		[$path, $operator, $value] = match (count($condition))
		{
			2 => [
				$condition[0] ?? null,
				is_array($condition[1] ?? null) ? Operator::In : Operator::Equal,
				$condition[1] ?? null,
			],
			3 => [$condition[0] ?? null, $condition[1] ?? null, $condition[2] ?? null],
			default => throw new InvalidFilterException($condition),
		};

		if (!is_string($path) || !in_array($path, self::FILTERABLE_PATHS, true))
		{
			return self::delegateCondition($condition, $dtoClass, $request);
		}

		$operator = $operator instanceof Operator
			? $operator
			: FilterValidator::validateOperator(is_string($operator) ? $operator : null);
		$sectionDtoClass = self::sectionDtoClassOf($dtoClass, $path);
		$field = self::requireFilterableField($sectionDtoClass, $path);

		return in_array($path, self::IDENTIFIER_COLLECTION_PATHS, true)
			? self::readIdentifierCollectionCondition($path, $operator, $value)
			: self::readSectionFieldCondition($path, $sectionDtoClass, $field, $operator, $value, $request)
		;
	}

	/**
	 * @throws InvalidFilterException
	 */
	private static function delegateCondition(array $condition, string $dtoClass, Request $request): Condition
	{
		$delegated = FilterStructure::create($condition, $dtoClass, $request)->getConditions()[0] ?? null;
		if (!$delegated instanceof Condition)
		{
			throw new InvalidFilterException($condition);
		}

		return $delegated;
	}

	/**
	 * The value of the condition is checked against the field of the section it names, by the transport
	 * and against the contract of the section; only the name of the condition stays the path, which is
	 * how the domain is given the field under the name the answer publishes it by.
	 *
	 * @throws InvalidFilterException
	 */
	private static function readSectionFieldCondition(
		string $path,
		string $sectionDtoClass,
		DtoField $field,
		Operator $operator,
		mixed $value,
		Request $request,
	): Condition
	{
		$delegated = self::delegateCondition(
			[$field->getPropertyName(), $operator->value, $value],
			$sectionDtoClass,
			$request,
		);

		return new Condition($path, $delegated->getOperator(), $delegated->getRightOperand());
	}

	/**
	 * @throws InvalidFilterException the value of a collection of users given for an operator that
	 *         carries no collection
	 * @throws InvalidRequestFieldTypeException a user that is not a positive identifier
	 */
	private static function readIdentifierCollectionCondition(
		string $path,
		Operator $operator,
		mixed $value,
	): Condition
	{
		// A list of users belongs to `in` and to no other operator; a single user to every other one.
		$isListOfUsers = is_array($value);
		if ($isListOfUsers !== ($operator === Operator::In))
		{
			throw new InvalidFilterException([$path, $operator->value, $value]);
		}

		foreach ($isListOfUsers ? $value : [$value] as $identifier)
		{
			if (!is_int($identifier))
			{
				throw new InvalidRequestFieldTypeException($path, 'int');
			}
			if ($identifier <= 0)
			{
				throw new InvalidRequestFieldTypeException($path, 'positive int');
			}
		}

		return new Condition($path, $operator, $value);
	}

	/**
	 * @throws DtoFieldRequiredAttributeException the contract publishes the field of the section
	 *         without {@see Filterable}, so the path is not open for filtering.
	 */
	private static function requireFilterableField(string $sectionDtoClass, string $path): DtoField
	{
		$fieldName = explode('.', $path, 2)[1];
		$sectionDto = self::requireDto($sectionDtoClass);
		$field = $sectionDto->getFields()[$fieldName] ?? null;
		if (!$field instanceof DtoField)
		{
			throw new \LogicException('The filter of the form list names a field outside the section: ' . $path);
		}

		if (!$field->isFilterable())
		{
			throw new DtoFieldRequiredAttributeException($sectionDto->getShortName(), $fieldName, Filterable::class);
		}

		return $field;
	}

	/**
	 * The contract of the section the path leads into. A path of {@see self::FILTERABLE_PATHS} that
	 * leads elsewhere is a mistake in this list rather than anything a client could send.
	 */
	private static function sectionDtoClassOf(string $dtoClass, string $path): string
	{
		$sectionField = self::requireDto($dtoClass)->getFields()[explode('.', $path, 2)[0]] ?? null;
		$sectionDtoClass = $sectionField instanceof DtoField
			? ($sectionField->getElementType() ?? $sectionField->getPropertyType())
			: null;
		if (!is_subclass_of($sectionDtoClass, Dto::class))
		{
			throw new \LogicException('The filter of the form list names a path that is not a section: ' . $path);
		}

		return $sectionDtoClass;
	}

	private static function requireDto(string $dtoClass): Dto
	{
		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

		return $dto;
	}

	/** Whether the node names one of the paths this structure reads, at any depth. */
	private static function namesFilterablePath(array $node): bool
	{
		foreach ($node as $item)
		{
			$namesPath = is_array($item)
				? self::namesFilterablePath($item)
				: (is_string($item) && in_array($item, self::FILTERABLE_PATHS, true));
			if ($namesPath)
			{
				return true;
			}
		}

		return false;
	}

	private static function adopt(FilterStructure $node): self
	{
		$structure = new self();
		$structure->logic = $node->logic();
		$structure->isNegative = $node->isNegative();
		$structure->rawData = $node->getList();
		foreach ($node->getConditions() as $condition)
		{
			$structure->conditions[] = $condition instanceof FilterStructure
				? self::adopt($condition)
				: $condition
			;
		}

		return $structure;
	}
}

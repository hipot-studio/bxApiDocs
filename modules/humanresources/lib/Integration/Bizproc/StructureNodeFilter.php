<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Internals\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * The node binding of the employee triggers: whether the employee of an event belongs to the structure
 * nodes the setting names. A trigger translates the verdict into a refusal code of its own.
 *
 * The check runs on every event, and once per published template holding the trigger, so the order of the
 * steps is the order of their cost: nothing is asked of the org structure until the answer can come from
 * nowhere else. The order is the list of steps in {@see self::check()} rather than the order of statements
 * of one method, and every answer the structure does give is kept for the event.
 */
class StructureNodeFilter
{
	/**
	 * Node types the binding is read against. A team named by the setting has to work exactly like a
	 * department, and the membership lookup answers with departments alone unless asked for both.
	 */
	private const FILTER_NODE_TYPES = [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM];

	/**
	 * Answers of the org structure, kept for the event being checked. The scope is the class and not the
	 * instance on purpose: the engine checks the rules of every published template on a fresh instance of
	 * the trigger ({@see \Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker}), so an instance field would
	 * come back empty on each of them and every template would ask the same questions again.
	 *
	 * The membership is held as a single slot: the templates of one event are checked one after another, so
	 * one slot drops the multiplier by templates and an event about another employee starts it over. The key
	 * tells "not asked yet" from "asked, and the employee belongs nowhere", and carries the activity of the
	 * membership as well - the two triggers read it differently and must not answer for each other.
	 *
	 * @var list<int>
	 */
	private static array $memberNodeIds = [];
	private static ?string $memberNodeIdsKey = null;

	/**
	 * Ancestors of a node, which do not depend on the employee of the event. Kept for the same span as the
	 * membership beside it and dropped together with it: the tree is rebuilt by the very events the filter
	 * runs on ({@see \Bitrix\HumanResources\Service\StructureWalkerService::removeNode()} moves the
	 * employees of a node into its parent), so a map living the whole process would answer a later event of
	 * a batch by a tree that no longer stands.
	 *
	 * @var array<int, list<int>>
	 */
	private static array $ancestorIdsByNodeId = [];

	/** @var list<mixed> */
	private array $value = [];

	/** @var list<mixed> */
	private array $resolvedValue = [];
	private int $userId = 0;
	private bool $withInactiveMembership = false;

	/** @var list<string> */
	private array $codes = [];

	/** @var list<int> */
	private array $selectedPlainNodeIds = [];

	/** @var list<int> */
	private array $selectedDeepNodeIds = [];

	/** @var list<int> */
	private array $employeeNodeIds = [];

	/**
	 * The binding reaches the check twice over, and the two answer different questions. Whether the binding
	 * was set at all is a question about what the author saved, so it is asked of the stored value; which
	 * nodes it names is a question about what that value amounts to right now, so it is asked of the
	 * resolved one. Asked of the stored value alone, a binding computed by an expression would name nothing
	 * and refuse every employee; asked of the resolved value alone, a binding that names something which
	 * resolves into nothing would look like a binding nobody ever set and take every employee.
	 *
	 * @param mixed $storedValue Binding as the template saved it, before any expression in it was resolved.
	 * @param mixed $resolvedValue The same binding with the expressions of the activity resolved.
	 * @param bool $withInactiveMembership Read the membership whatever its activity: a dismissal turns the
	 *                                     membership off before the event about it is raised.
	 */
	public function check(
		int $userId,
		mixed $storedValue,
		mixed $resolvedValue,
		bool $withInactiveMembership = false,
	): StructureNodeFilterVerdict
	{
		$this->beginCheck($userId, $storedValue, $resolvedValue, $withInactiveMembership);

		return $this->checkEmployee()
			?? $this->checkBindingIsSet()
			?? $this->checkBindingResolvesIntoCodes()
			?? $this->checkBindingNamesNoEmployee()
			?? $this->checkSelectedNodes()
			?? $this->checkEmployeeNodes()
			?? $this->checkDirectMembership()
			?? $this->checkAncestors()
		;
	}

	/**
	 * Drops the answers of the org structure kept for the event. Intended for use in tests.
	 */
	public static function resetLookupCache(): void
	{
		self::$memberNodeIds = [];
		self::$memberNodeIdsKey = null;
		self::$ancestorIdsByNodeId = [];
	}

	/**
	 * The questions the filter asks the org structure, each in one place and none of them asked anywhere
	 * else. Protected so that a test can answer them without a portal behind it: the repositories they
	 * reach are final classes of the module.
	 *
	 * @return list<int>
	 */
	protected function findMemberNodeIds(int $userId, bool $withInactiveMembership): array
	{
		return Container::getNodeMemberRepository()
			->findAllByEntityIds(
				entityIds: [$userId],
				nodeTypes: self::FILTER_NODE_TYPES,
				withVirtualUsers: false,
				memberActive: $withInactiveMembership ? null : true,
			)
			->getUniqueNodeIds()
		;
	}

	/**
	 * The chains of several nodes are read in one go: they do not depend on the binding, so one read
	 * answers for every node of the employee and for every template of the event.
	 *
	 * @param list<int> $nodeIds
	 *
	 * @return array<int, list<int>>
	 */
	protected function findAncestorIdsByNodeIds(array $nodeIds): array
	{
		return Container::getNodePathRepository()->findParentIdsByNodeIds($nodeIds);
	}

	/**
	 * @param list<string> $accessCodes
	 *
	 * @return list<int>
	 */
	protected function findNodeIdsByAccessCodes(array $accessCodes): array
	{
		return Container::getNodeAccessCodeService()->getNodeIdsByAccessCodes($accessCodes);
	}

	private function beginCheck(
		int $userId,
		mixed $storedValue,
		mixed $resolvedValue,
		bool $withInactiveMembership,
	): void
	{
		$this->userId = $userId;
		$this->value = \CBPHelper::flatten($storedValue);
		$this->resolvedValue = \CBPHelper::flatten($resolvedValue);
		$this->withInactiveMembership = $withInactiveMembership;
		$this->codes = [];
		$this->selectedPlainNodeIds = [];
		$this->selectedDeepNodeIds = [];
		$this->employeeNodeIds = [];
	}

	/**
	 * A trigger of the employee is public: any raiser of its event reaches the check, not the launcher of
	 * the module alone. An event carrying no employee has nothing to check.
	 */
	private function checkEmployee(): ?StructureNodeFilterVerdict
	{
		return $this->userId > 0 ? null : StructureNodeFilterVerdict::NoEmployee;
	}

	/**
	 * A binding that was never set takes any employee. The emptiness is judged on the value as the template
	 * saved it, so that a value that names something but resolves into nothing is told apart from it.
	 */
	private function checkBindingIsSet(): ?StructureNodeFilterVerdict
	{
		return \CBPHelper::isEmptyValue($this->value) ? StructureNodeFilterVerdict::Applicable : null;
	}

	/**
	 * The codes are read off the resolved value: a binding written as an expression names its nodes only
	 * after the activity has computed it.
	 */
	private function checkBindingResolvesIntoCodes(): ?StructureNodeFilterVerdict
	{
		$this->codes = StructureNodeValue::normalizeCodes($this->resolvedValue);

		return $this->codes ? null : StructureNodeFilterVerdict::UnresolvedFilter;
	}

	/**
	 * An employee named by hand is a setting of the old kind, and the whole binding is refused for it: a
	 * silent narrowing down to the departments standing next to him would change what a published scenario
	 * does no less than taking everyone would.
	 */
	private function checkBindingNamesNoEmployee(): ?StructureNodeFilterVerdict
	{
		return StructureNodeValue::hasUserCodes($this->codes)
			? StructureNodeFilterVerdict::StaleUserCodes
			: null
		;
	}

	/**
	 * User groups, socnet groups and "all departments" name no structure node. Reading such a binding as an
	 * empty one would start the scenario for every employee of the portal.
	 */
	private function checkSelectedNodes(): ?StructureNodeFilterVerdict
	{
		$this->selectedPlainNodeIds = $this->selectedNodeIds(withSubordinates: false);
		$this->selectedDeepNodeIds = $this->selectedNodeIds(withSubordinates: true);

		return $this->selectedPlainNodeIds || $this->selectedDeepNodeIds
			? null
			: StructureNodeFilterVerdict::NoStructureCodes
		;
	}

	/**
	 * A portal whose structure is not converted yet answers the same way as an employee outside the
	 * structure, and both stay unmatched instead of taking everyone.
	 */
	private function checkEmployeeNodes(): ?StructureNodeFilterVerdict
	{
		$this->employeeNodeIds = $this->memberNodeIds();

		return $this->employeeNodeIds ? null : StructureNodeFilterVerdict::NoMemberNodes;
	}

	/**
	 * A node picked together with its subordinates takes its own members too, and a node of its own is not
	 * among its ancestors.
	 */
	private function checkDirectMembership(): ?StructureNodeFilterVerdict
	{
		$selectedNodeIds = array_merge($this->selectedPlainNodeIds, $this->selectedDeepNodeIds);

		return array_intersect($this->employeeNodeIds, $selectedNodeIds) !== []
			? StructureNodeFilterVerdict::Applicable
			: null
		;
	}

	private function checkAncestors(): StructureNodeFilterVerdict
	{
		if (!$this->selectedDeepNodeIds)
		{
			return StructureNodeFilterVerdict::OutsideSelection;
		}

		$this->rememberAncestorIds($this->employeeNodeIds);

		foreach ($this->employeeNodeIds as $nodeId)
		{
			if (array_intersect($this->ancestorIds($nodeId), $this->selectedDeepNodeIds) !== [])
			{
				return StructureNodeFilterVerdict::Applicable;
			}
		}

		return StructureNodeFilterVerdict::OutsideSelection;
	}

	/**
	 * Nodes the binding names, of one kind of pick. A code of the new structure carries the node id itself,
	 * while a code of the old one carries a section id and is translated through the access code of the
	 * node. Each kind of pick naming a section costs one translation and a binding naming none costs no
	 * query: the translation answers with node ids alone, so one call for both kinds could not tell which
	 * kind of pick a node id came from.
	 *
	 * @return list<int>
	 */
	private function selectedNodeIds(bool $withSubordinates): array
	{
		$nodeIds = StructureNodeValue::nodeIds($this->codes, $withSubordinates);
		$sectionAccessCodes = StructureNodeValue::sectionAccessCodes($this->codes, $withSubordinates);
		if (!$sectionAccessCodes)
		{
			return $nodeIds;
		}

		return array_merge($nodeIds, $this->findNodeIdsByAccessCodes($sectionAccessCodes));
	}

	/**
	 * Structure nodes the employee belongs to, asked for once per employee.
	 *
	 * @return list<int>
	 */
	private function memberNodeIds(): array
	{
		$key = $this->userId . ':' . ($this->withInactiveMembership ? 'any' : 'active');
		if (self::$memberNodeIdsKey !== $key)
		{
			self::$memberNodeIds = $this->findMemberNodeIds($this->userId, $this->withInactiveMembership);
			self::$memberNodeIdsKey = $key;
			self::$ancestorIdsByNodeId = [];
		}

		return self::$memberNodeIds;
	}

	/**
	 * The whole chain of ancestors of every node not read yet, asked for once per node.
	 *
	 * @param list<int> $nodeIds
	 */
	private function rememberAncestorIds(array $nodeIds): void
	{
		$unknownNodeIds = array_values(array_diff($nodeIds, array_keys(self::$ancestorIdsByNodeId)));
		if (!$unknownNodeIds)
		{
			return;
		}

		self::$ancestorIdsByNodeId += $this->findAncestorIdsByNodeIds($unknownNodeIds);
	}

	/**
	 * @return list<int>
	 */
	private function ancestorIds(int $nodeId): array
	{
		return self::$ancestorIdsByNodeId[$nodeId] ?? [];
	}
}

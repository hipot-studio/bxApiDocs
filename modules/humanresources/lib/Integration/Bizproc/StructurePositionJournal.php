<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Internals\Repository\Query\RealUserFilter;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\PositionJournal;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UserTable;

/**
 * Collects member events of a hit and, in a background job, compares them with the committed structure,
 * so that every employee gets at most one position change however many events described it.
 */
class StructurePositionJournal extends PositionJournal
{
	private const ABSENT = false;

	public const PORTION_SIZE = 500;

	/** @var array<int, array<int, NodeMemberRole|null|false>> First known state by node id by user id. */
	private array $previous = [];

	/** @var array<int, string> */
	private array $removedNodeNames = [];

	/** @var array<int, int> Parent by removed department id: its members are moved there. */
	private array $removedDepartmentParents = [];

	/** @var array<int, array<int, true>> Nodes whose membership got its role rewritten, by user id. */
	private array $roleRewrittenNodeIds = [];

	private bool $flushScheduled = false;

	private bool $suspended = false;

	public function __construct(private readonly Application $application)
	{}

	public function record(string $eventName, array $eventData): void
	{
		if (!$this->isActive())
		{
			return;
		}

		if ($eventName === EventName::OnNodeDeleted->name)
		{
			$this->rememberRemovedNode($eventData['node'] ?? null);

			return;
		}

		$member = $eventData['member'] ?? null;
		if (!$this->isUserMember($member))
		{
			return;
		}

		if ($eventName === EventName::OnMemberAdded->name)
		{
			$this->rememberPrevious($member->entityId, $member->nodeId, self::ABSENT);
		}
		elseif ($eventName === EventName::OnMemberDeleted->name)
		{
			$this->rememberPrevious($member->entityId, $member->nodeId, $this->stateOf($member));
		}
		elseif ($eventName === EventName::OnMemberUpdated->name)
		{
			$previousMember = $eventData['previousMember'] ?? null;
			if (
				!$previousMember instanceof NodeMember
				|| $this->isIdleActivityResync($eventData, $member, $previousMember)
			)
			{
				return;
			}

			$this->rememberPrevious($member->entityId, $previousMember->nodeId, $this->stateOf($previousMember));
			if ($member->nodeId !== $previousMember->nodeId)
			{
				$this->rememberPrevious($member->entityId, $member->nodeId, self::ABSENT);
			}
			elseif (in_array('role', $eventData['fields'] ?? [], true))
			{
				$this->roleRewrittenNodeIds[$member->entityId][$member->nodeId] = true;
			}
		}
	}

	public function flush(): void
	{
		if (!$this->isActive())
		{
			return;
		}

		$this->flushScheduled = false;
		$previous = $this->previous;
		$removedNodeNames = $this->removedNodeNames;
		$removedDepartmentParents = $this->removedDepartmentParents;
		$roleRewrittenNodeIds = $this->roleRewrittenNodeIds;
		$this->resetRecords();

		foreach (array_chunk($previous, self::PORTION_SIZE, true) as $portion)
		{
			$this->schedulePortionFlush(
				fn() => $this->flushPortion($portion, $removedNodeNames, $removedDepartmentParents, $roleRewrittenNodeIds),
			);
		}
	}

	/**
	 * @param array<int, array<int, NodeMemberRole|null|false>> $previous
	 * @param array<int, string> $removedNodeNames
	 * @param array<int, int> $removedDepartmentParents
	 * @param array<int, array<int, true>> $roleRewrittenNodeIds
	 */
	private function flushPortion(
		array $previous,
		array $removedNodeNames,
		array $removedDepartmentParents,
		array $roleRewrittenNodeIds,
	): void
	{
		$userIds = $this->findRealUserIds(array_keys($previous));
		if (!$userIds)
		{
			return;
		}

		$after = [];
		foreach ($this->findPositions($userIds) as $position)
		{
			$after[$position->entityId][$position->nodeId] = $this->roleOf($this->roleIdsOf($position));
		}

		$moveTargets = $this->findMoveTargetsOfRemovedDepartments($previous, $after, $removedDepartmentParents);

		$liveNodeIds = [];
		foreach ($userIds as $userId)
		{
			$liveNodeIds += $previous[$userId];
			if (isset($moveTargets[$userId]))
			{
				$liveNodeIds[$moveTargets[$userId]] = true;
			}
		}
		$liveNodeIds = array_keys(array_diff_key($liveNodeIds, $removedNodeNames));

		$nodeNames = ($liveNodeIds ? $this->findNodeNames($liveNodeIds) : []) + $removedNodeNames;

		$changes = [];
		$diff = new StructurePositionDiff();
		foreach ($userIds as $userId)
		{
			$journaled = $previous[$userId];
			$current = $after[$userId] ?? [];
			$before = array_diff_key($current, $journaled)
				+ array_filter($journaled, static fn(NodeMemberRole|null|false $state): bool => $state !== self::ABSENT)
			;

			$moveTarget = $moveTargets[$userId]
				?? $this->findRoleRewrittenMoveTarget($before, $current, $roleRewrittenNodeIds[$userId] ?? [])
			;
			$change = $diff->change($userId, $before, $current, $moveTarget, $nodeNames);
			if ($change !== null)
			{
				$changes[] = $change;
			}
		}

		if ($changes)
		{
			$this->launch($changes);
		}
	}

	/**
	 * Members of a removed department are moved to its parent; the one already there keeps that membership
	 * and has no other trace of the move than the parent being the target.
	 *
	 * @param array<int, array<int, NodeMemberRole|null|false>> $previous
	 * @param array<int, array<int, ?NodeMemberRole>> $after
	 * @param array<int, int> $removedDepartmentParents
	 * @return array<int, int>
	 */
	private function findMoveTargetsOfRemovedDepartments(
		array $previous,
		array $after,
		array $removedDepartmentParents,
	): array
	{
		$moveTargets = [];
		foreach ($removedDepartmentParents as $removedNodeId => $parentId)
		{
			foreach ($previous as $userId => $journaled)
			{
				if (array_key_exists($removedNodeId, $journaled) && array_key_exists($parentId, $after[$userId] ?? []))
				{
					$moveTargets[$userId] = $parentId;
				}
			}
		}

		return $moveTargets;
	}

	/**
	 * Moving into a node the employee is already a member of rewrites the role there instead of adding a membership,
	 * so the single node of the same type as the removed ones that got its role rewritten is taken for the target.
	 * Only a rewrite that kept the role counts: a changed role is indistinguishable from a separate role change.
	 *
	 * @param array<int, ?NodeMemberRole> $before
	 * @param array<int, ?NodeMemberRole> $after
	 * @param array<int, true> $roleRewrittenNodeIds
	 */
	private function findRoleRewrittenMoveTarget(array $before, array $after, array $roleRewrittenNodeIds): ?int
	{
		if (array_diff_key($after, $before))
		{
			return null;
		}

		$removedNodeTypes = array_map(self::nodeTypeOf(...), array_diff_key($before, $after));
		$candidates = array_filter(
			array_intersect_key($after, $roleRewrittenNodeIds),
			static fn(?NodeMemberRole $role, int $nodeId): bool => $role !== null
				&& $role === ($before[$nodeId] ?? null)
				&& in_array(self::nodeTypeOf($role), $removedNodeTypes, true)
			,
			ARRAY_FILTER_USE_BOTH,
		);

		return count($candidates) === 1 ? array_key_first($candidates) : null;
	}

	private static function nodeTypeOf(?NodeMemberRole $role): ?NodeEntityType
	{
		return match (true)
		{
			$role === null => null,
			in_array($role, NodeMemberRole::teamRoles(), true) => NodeEntityType::TEAM,
			default => NodeEntityType::DEPARTMENT,
		};
	}

	/**
	 * Stays off for the rest of the hit once the structure is found unconverted: the conversion of the old one
	 * writes membership of the whole portal at once and none of it is a position change.
	 */
	private function isActive(): bool
	{
		if (!$this->isProcessEngineInstalled() || $this->suspended)
		{
			return false;
		}

		if (!$this->isStructureConverted())
		{
			$this->suspended = true;
			$this->resetRecords();

			return false;
		}

		return true;
	}

	protected function isProcessEngineInstalled(): bool
	{
		return ModuleManager::isModuleInstalled('bizproc');
	}

	protected function isStructureConverted(): bool
	{
		return Storage::instance()->isCompanyStructureConverted();
	}

	/**
	 * Drops virtual users only: a deleted user has no row left and still gets the removal from every node.
	 *
	 * @param list<int> $userIds
	 * @return list<int>
	 */
	protected function findRealUserIds(array $userIds): array
	{
		$existingUserIds = array_map(
			'intval',
			array_column(UserTable::query()->setSelect(['ID'])->whereIn('ID', $userIds)->fetchAll(), 'ID'),
		);
		$virtualUserIds = array_diff($existingUserIds, RealUserFilter::filterRealUserIds($existingUserIds));

		return array_values(array_diff($userIds, $virtualUserIds));
	}

	/**
	 * @param list<int> $userIds
	 */
	protected function findPositions(array $userIds): NodeMemberCollection
	{
		return InternalContainer::getNodeMemberRepository()->findAllByEntityIds(
			entityIds: $userIds,
			memberEntityType: MemberEntityType::USER,
			nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			nodeActiveFilter: NodeActiveFilter::ALL,
			withVirtualUsers: false,
			memberActive: true,
		);
	}

	/**
	 * @param list<int> $nodeIds
	 * @return array<int, string>
	 */
	protected function findNodeNames(array $nodeIds): array
	{
		$nodes = InternalContainer::getNodeRepository()->findAllByIds(
			$nodeIds,
			nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			activeFilter: NodeActiveFilter::ALL,
		);

		$names = [];
		foreach ($nodes as $node)
		{
			$names[$node->id] = $node->name ?? '';
		}

		return $names;
	}

	protected function scheduleFlush(): void
	{
		$this->application->addBackgroundJob([$this, 'flush']);
	}

	/**
	 * Every portion is a job of its own, so that a failed or heavy portion does not take the rest down with it.
	 */
	protected function schedulePortionFlush(\Closure $flushPortion): void
	{
		$this->application->addBackgroundJob($flushPortion);
	}

	protected function launcher(): ProcessLauncherInterface
	{
		return Container::getBizprocProcessLauncher();
	}

	protected function log(string $message): void
	{
		self::logger()->error($message);
	}

	private function isUserMember(mixed $member): bool
	{
		return $member instanceof NodeMember
			&& $member->entityType === MemberEntityType::USER
			&& $member->entityId > 0
		;
	}

	/**
	 * The name of a removed node is read nowhere else once the node row is gone.
	 */
	private function rememberRemovedNode(mixed $node): void
	{
		if ($node instanceof Node && $node->id)
		{
			$this->removedNodeNames[$node->id] = $node->name ?? '';
			if ($node->type !== NodeEntityType::TEAM && $node->parentId)
			{
				$this->removedDepartmentParents[$node->id] = $node->parentId;
			}
		}
	}

	private function resetRecords(): void
	{
		$this->previous = $this->removedNodeNames = $this->removedDepartmentParents = $this->roleRewrittenNodeIds = [];
	}

	private function rememberPrevious(int $userId, int $nodeId, NodeMemberRole|null|false $state): void
	{
		if (!array_key_exists($nodeId, $this->previous[$userId] ?? []))
		{
			$this->previous[$userId][$nodeId] = $state;
		}

		$this->requestFlush();
	}

	private function requestFlush(): void
	{
		if (!$this->flushScheduled)
		{
			$this->flushScheduled = true;
			$this->scheduleFlush();
		}
	}

	/**
	 * The flag is resent whenever the legacy department field may have drifted, whether it changed or not.
	 */
	private function isIdleActivityResync(array $eventData, NodeMember $member, NodeMember $previousMember): bool
	{
		return ($eventData['fields'] ?? null) === ['active'] && $previousMember->active === $member->active;
	}

	private function stateOf(NodeMember $member): NodeMemberRole|null|false
	{
		return $member->active === false ? self::ABSENT : $this->roleOf($this->roleIdsOf($member));
	}

	/**
	 * @return list<int>
	 */
	private function roleIdsOf(NodeMember $member): array
	{
		return array_filter($member->roles ?? []) ?: array_filter([$member->role]);
	}

	/**
	 * @param list<int> $roleIds
	 */
	private function roleOf(array $roleIds): ?NodeMemberRole
	{
		$roleHelper = Container::getRoleHelperService();
		$roles = array_map(
			static fn(int $roleId): ?NodeMemberRole => NodeMemberRole::tryFrom(
				(string)$roleHelper->getById($roleId)?->xmlId,
			),
			$roleIds,
		);

		return StructurePositionDiff::seniorRole($roles);
	}

	/**
	 * @param list<StructurePositionChange> $changes
	 */
	private function launch(array $changes): void
	{
		try
		{
			$errors = $this->launcher()->launchForPositionChanges(...$changes)->getErrors();
		}
		catch (\Throwable $exception)
		{
			$this->log('Structure position trigger launch failed: ' . $exception->getMessage());

			return;
		}

		$failures = [];
		$refusalPrefix = StructurePositionChangedTrigger::REFUSAL_CODE_PREFIX;
		foreach ($errors as $error)
		{
			if (!str_starts_with((string)$error->getCode(), $refusalPrefix))
			{
				$failures[] = $error->getMessage();
			}
		}

		if ($failures)
		{
			$this->log('Structure position trigger launch failed: ' . implode('; ', $failures));
		}
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Service;

use Bitrix\HumanResources\Access\Model\ChatListModel;
use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Exception\NodeAccessFilterException;
use Bitrix\HumanResources\Item\Collection\NodeRelationCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\NodeRelation;
use Bitrix\HumanResources\Model\NodeBackwardAccessCodeTable;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeEntityTypeCollection;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Structure;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Mapper\DepartmentRelationMapper;
use Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources\Provider\DepartmentProvider;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupRepository;
use Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\CollabRelationInfo;

class StructureRelationService
{
	/**
	 * Two linked nodes are the same project member only when their ids come from the same sequence. UF_SG_DEPT and a
	 * node backward access code carry intranet department ids; a node created in the new structure, department or
	 * team, has only its node id. The sequences overlap: node 29 and intranet department 29 are two different
	 * departments of one portal, so the key names the sequence next to the id.
	 */
	private const LEGACY_DEPARTMENT_KEY_PREFIX = 'department:';
	private const STRUCTURE_NODE_KEY_PREFIX = 'node:';

	public const ERROR_NODE_ACCESS_DENIED = 'SONET_STRUCTURE_NODE_ACCESS_DENIED';
	public const ERROR_NODE_NOT_FOUND = 'SONET_STRUCTURE_NODE_NOT_FOUND';
	public const ERROR_TEAMS_NOT_AVAILABLE = Structure::ERROR_TEAMS_NOT_AVAILABLE;

	/** @var array<int, CollabRelationInfo[]> */
	private array $collabRelationsCache = [];

	/** @var array<int, int[]> */
	private array $chatRelationsCache = [];

	/** @var array<int, array<int, array<int, bool>>> */
	private array $nodeCollabEditAccessCache = [];

	public function __construct(
		private readonly MemberEntityMapper $memberEntityMapper,
		private readonly DepartmentRelationMapper $departmentRelationMapper,
		private readonly WorkgroupRepository $workgroupRepository,
		private readonly DepartmentProvider $departmentProvider,
	)
	{
	}

	public function getEmployeeIds(
		int $nodeId,
		bool $withChildNodes,
		int $offset = 0,
		int $limit = 500,
	): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$employees = Container::getNodeMemberService()
			->getPagedEmployees($nodeId, $withChildNodes, $offset, $limit)
			->getValues()
		;

		return array_map(
			static fn(NodeMember $employee): int => $employee->entityId,
			$employees,
		);
	}

	/**
	 * @return CollabRelationInfo[]
	 */
	public function getCollabRelations(int $nodeId): array
	{
		if (isset($this->collabRelationsCache[$nodeId]))
		{
			return $this->collabRelationsCache[$nodeId];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$relations = Container::getNodeRelationRepository()
			->findRelationsByNodeIdAndRelationType($nodeId, RelationEntityType::COLLAB)
		;

		$this->collabRelationsCache[$nodeId] = array_map(
			static fn(NodeRelation $relation) => new CollabRelationInfo(
				$relation->entityId,
				$relation->createdBy,
			),
			$relations->getValues(),
		);

		return $this->collabRelationsCache[$nodeId];
	}

	/**
	 * @param string[] $accessCodes Structure node access codes: departments (D123, DR456) and teams
	 * @param int $initiatorId The person whose edit attaches the nodes; 0 when the attachment carries its own
	 *                         authorization and this side must not second-guess it: the humanresources form
	 *                         mirrors a node relation it has already granted onto the project.
	 */
	public function linkNodes(array $accessCodes, int $collabId, int $initiatorId = 0): Result
	{
		$result = new Result();

		if (!Loader::includeModule('humanresources'))
		{
			return $result;
		}

		$deniedCodes = $this->findCodesBeyondInitiatorCollabEditAccess($accessCodes, $initiatorId);
		if (!empty($deniedCodes))
		{
			return $result->addError($this->makeNodeAccessError());
		}

		$relations = new NodeRelationCollection();
		$nodeService = \Bitrix\HumanResources\Public\Service\Container::getNodeService();
		foreach (array_unique($accessCodes) as $accessCode)
		{
			$node = $nodeService->getByAccessCode($accessCode);
			if ($node?->id === null)
			{
				return $result->addError($this->makeNodeAccessError());
			}

			$relations->add(new NodeRelation(
				nodeId: $node->id,
				entityId: $collabId,
				entityType: RelationEntityType::COLLAB,
				withChildNodes: $this->isRecursiveAccessCode($accessCode),
			));
		}

		if (!$relations->empty())
		{
			Container::getNodeRelationService()->linkNodeRelationCollection(
				$relations,
			);
		}

		return $result;
	}

	/**
	 * @param string[] $accessCodes Structure node access codes: departments (D123, DR456) and teams
	 * @param int $initiatorId Same meaning as in linkNodes(): a node is detached under the same right that
	 *                         attaches it, so that a project cannot reach a state it is unable to leave.
	 */
	public function unlinkNodes(array $accessCodes, int $collabId, int $initiatorId = 0): Result
	{
		$result = new Result();

		if (empty($accessCodes))
		{
			return $result;
		}

		$deniedCodes = $this->findCodesBeyondInitiatorCollabEditAccess($accessCodes, $initiatorId);
		if (!empty($deniedCodes))
		{
			return $result->addError($this->makeNodeAccessError());
		}

		$this->unlinkNodeRelations($accessCodes, $collabId);
		$this->unlinkLegacyDepartments($accessCodes, $collabId, $initiatorId);

		return $result;
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function validateTeamsSupported(array $accessCodes): Result
	{
		$result = new Result();

		foreach ($accessCodes as $accessCode)
		{
			if ($this->memberEntityMapper->fromAccessCode($accessCode)?->type !== MemberEntityType::Team)
			{
				continue;
			}

			if (!Structure::isTeamsAvailable())
			{
				$result->addError($this->makeTeamsNotAvailableError());
			}

			break;
		}

		return $result;
	}

	/**
	 * The same question linkNodes() and unlinkNodes() answer for themselves, asked before anything is written:
	 * a composition edit carrying a team whose collab list the initiator may not edit is refused whole, instead of
	 * applying the half of it that happens to come first. Callers that write a single node need no pre-check - the write
	 * methods keep their own.
	 *
	 * @param string[] $accessCodes
	 */
	public function validateNodesAccess(array $accessCodes, int $initiatorId): Result
	{
		$result = new Result();

		$deniedCodes = $this->findCodesBeyondInitiatorCollabEditAccess($accessCodes, $initiatorId);
		if (!empty($deniedCodes))
		{
			$result->addError($this->makeNodeAccessError());
		}

		return $result;
	}

	/**
	 * The resolution linkNodes() performs on every code, asked before anything is written: a composition edit
	 * naming a node the structure does not hold is refused whole. Nothing wraps the write in a transaction, so a
	 * refusal raised after it would leave the members written before the missing node in place.
	 *
	 * Only the codes being attached belong here. A node that has disappeared from the structure must stay
	 * detachable, or a project would keep a member it can never drop.
	 *
	 * @param string[] $accessCodes
	 */
	public function validateNodesExist(array $accessCodes): Result
	{
		$result = new Result();

		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return $result;
		}

		// The same resolver linkNodes() uses for the write, so the two can never disagree on which node a code names.
		$nodeService = \Bitrix\HumanResources\Public\Service\Container::getNodeService();
		foreach (array_unique($accessCodes) as $accessCode)
		{
			if ($nodeService->getByAccessCode($accessCode)?->id === null)
			{
				return $result->addError($this->makeNodeNotFoundError());
			}
		}

		return $result;
	}

	/**
	 * The counterpart of validateNodesAccess() for a caller that only needs to know whether the user is allowed to
	 * name the node at all. A code no node answers is refused exactly as an invisible one, so a guessed id and a
	 * hidden team give the same answer.
	 *
	 * @param string[] $accessCodes
	 */
	public function validateNodesVisibility(array $accessCodes, int $userId): Result
	{
		$result = new Result();

		if (!empty($this->findCodesInvisibleToUser($accessCodes, $userId)))
		{
			$result->addError($this->makeNodeAccessError());
		}

		return $result;
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function areNodesVisibleToUser(array $accessCodes, int $userId): bool
	{
		return empty($this->findCodesInvisibleToUser($accessCodes, $userId));
	}

	public function filterVisibleNodesForUser(
		MemberEntityCollection $nodes,
		int $userId,
	): MemberEntityCollection
	{
		if ($userId <= 0 || $nodes->isEmpty() || !Loader::includeModule('humanresources'))
		{
			return $nodes;
		}

		$teamIds = array_values(array_unique($nodes->filterByType(MemberEntityType::Team)->getIds()));
		if (empty($teamIds))
		{
			return $nodes;
		}

		$visibleTeamIds = array_fill_keys($this->findVisibleTeamIds($teamIds, $userId), true);

		return $nodes->filter(
			static fn(MemberEntity $node): bool => (
				$node->type !== MemberEntityType::Team
				|| isset($visibleTeamIds[$node->id])
			),
		);
	}

	/**
	 * Resolves all named structure members for a project card in a fixed number of HR queries. Departments keep
	 * their legacy visibility semantics; teams are filtered by the sender's view permission in one batch.
	 *
	 * @param string[] $accessCodes
	 */
	public function getVisibleNodesByAccessCodes(array $accessCodes, int $userId): MemberEntityCollection
	{
		$result = new MemberEntityCollection();
		$accessCodes = array_values(array_unique($accessCodes));
		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return $result;
		}

		$membersByCode = [];
		foreach ($accessCodes as $accessCode)
		{
			$member = $this->memberEntityMapper->fromAccessCode($accessCode);
			if ($member?->id !== null && $member->type !== null && $member->type !== MemberEntityType::User)
			{
				$membersByCode[$accessCode] = $member;
			}
		}

		if (empty($membersByCode))
		{
			return $result;
		}

		$nodeIds = [];
		$nodeIdsByAccessCode = [];
		$departmentAccessCodes = [];
		foreach ($membersByCode as $accessCode => $member)
		{
			if ($member->type === MemberEntityType::Team)
			{
				$nodeIds[] = $member->id;
				$nodeIdsByAccessCode[$accessCode] = $member->id;

				continue;
			}

			$flatAccessCode = AccessCodeType::IntranetDepartmentType->value . $member->id;
			$departmentAccessCodes[$accessCode] = $flatAccessCode;
		}

		if (!empty($departmentAccessCodes))
		{
			$rows = NodeBackwardAccessCodeTable::query()
				->setSelect(['NODE_ID', 'ACCESS_CODE'])
				->whereIn('ACCESS_CODE', array_values(array_unique($departmentAccessCodes)))
				->fetchAll()
			;
			$departmentNodeIds = array_column($rows, 'NODE_ID', 'ACCESS_CODE');
			foreach ($departmentAccessCodes as $accessCode => $flatAccessCode)
			{
				$nodeId = (int)($departmentNodeIds[$flatAccessCode] ?? 0);
				if ($nodeId > 0)
				{
					$nodeIds[] = $nodeId;
					$nodeIdsByAccessCode[$accessCode] = $nodeId;
				}
			}
		}

		if (empty($nodeIds))
		{
			return $result;
		}

		$nodeRows = NodeTable::query()
			->setSelect(['ID', 'NAME', 'TYPE'])
			->whereIn('ID', array_values(array_unique($nodeIds)))
			->fetchAll()
		;
		$nodesById = array_column($nodeRows, null, 'ID');
		$teamIds = array_values(array_unique(array_map(
			static fn(MemberEntity $member): int => $member->type === MemberEntityType::Team ? (int)$member->id : 0,
			$membersByCode,
		)));
		$teamIds = array_values(array_filter($teamIds));

		$visibleTeamIds = $userId > 0
			? array_fill_keys($this->findVisibleTeamIds($teamIds, $userId), true)
			: array_fill_keys($teamIds, true)
		;

		foreach ($membersByCode as $accessCode => $member)
		{
			$nodeId = $nodeIdsByAccessCode[$accessCode] ?? 0;
			$node = $nodesById[$nodeId] ?? null;
			$nodeType = NodeEntityType::tryFrom((string)($node['TYPE'] ?? ''));
			if ($member->type === MemberEntityType::Team)
			{
				if ($nodeType !== NodeEntityType::TEAM || !isset($visibleTeamIds[$member->id]))
				{
					continue;
				}
			}
			else
			{
				if ($nodeType !== NodeEntityType::DEPARTMENT)
				{
					continue;
				}
			}

			$name = trim((string)($node['NAME'] ?? ''));
			if ($name === '')
			{
				continue;
			}

			$result->add(new MemberEntity(
				id: $member->id,
				type: $member->type,
				withChildNodes: $member->withChildNodes,
				name: $name,
			));
		}

		return $result;
	}

	/**
	 * @param int[] $teamIds
	 * @return int[]
	 */
	private function findVisibleTeamIds(array $teamIds, int $userId): array
	{
		if (empty($teamIds))
		{
			return [];
		}

		try
		{
			$accessFilter = new NodeAccessFilter(StructureAction::ViewAction, $userId);
			$accessFilter->setNodeEntityTypes(new NodeEntityTypeCollection(NodeEntityType::TEAM));

			return array_map(
				static fn(array $row): int => (int)$row['ID'],
				NodeTable::query()
					->setSelect(['ID'])
					->whereIn('ID', array_values(array_unique($teamIds)))
					->where($accessFilter->prepareFilter())
					->fetchAll(),
			);
		}
		catch (NodeAccessFilterException)
		{
			return [];
		}
	}

	/**
	 * A team joins a project only if the initiator may edit its collab list. Visibility alone is insufficient:
	 * the relation synchronizes current and future team members with the project and its chat.
	 *
	 * Departments are deliberately left out: they have been reaching projects through this path since long
	 * before teams, including for initiators without an HR role. Teams are the surface this path opened, so the
	 * new team-collab permission boundary applies only to them.
	 *
	 * @param string[] $accessCodes
	 * @return string[] the codes the initiator may not touch
	 */
	private function findCodesBeyondInitiatorCollabEditAccess(array $accessCodes, int $initiatorId): array
	{
		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$deniedCodes = [];
		foreach ($accessCodes as $accessCode)
		{
			$member = $this->memberEntityMapper->fromAccessCode($accessCode);
			if ($member?->type !== MemberEntityType::Team)
			{
				continue;
			}

			// The same resolver NodeRelationService uses for the link itself, so the check and the write
			// can never disagree on which node a code names.
			$node = Container::getNodeRepository()->getByAccessCode($accessCode);
			if ($node === null || !$node->isTeam())
			{
				$deniedCodes[] = $accessCode;

				continue;
			}

			if (
				$initiatorId > 0
				&& !$this->canEditNodeCollabs(
					(int)$node->id,
					$initiatorId,
					$this->isRecursiveAccessCode($accessCode),
				)
			)
			{
				$deniedCodes[] = $accessCode;
			}
		}

		return $deniedCodes;
	}

	/**
	 * Message recipients only need permission to see the referenced team. This check deliberately differs from
	 * the write path above, which requires permission to edit the team's collab list.
	 *
	 * A composition edit is judged whole, every team the client sent and not only the ones the diff turns into
	 * additions, so the answer costs a fixed number of HR queries instead of one resolution and one access check
	 * per team named.
	 *
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function findCodesInvisibleToUser(array $accessCodes, int $userId): array
	{
		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$teamIdsByCode = [];
		foreach (array_unique($accessCodes) as $accessCode)
		{
			$member = $this->memberEntityMapper->fromAccessCode($accessCode);
			if ($member?->type === MemberEntityType::Team && $member->id !== null)
			{
				$teamIdsByCode[$accessCode] = (int)$member->id;
			}
		}

		if (empty($teamIdsByCode))
		{
			return [];
		}

		$teamIds = array_values(array_unique($teamIdsByCode));
		$existingTeamIds = $this->findExistingTeamIds($teamIds);
		$visibleTeamIds = $userId > 0
			? array_fill_keys($this->findVisibleTeamIds($teamIds, $userId), true)
			: array_fill_keys($teamIds, true)
		;

		$deniedCodes = [];
		foreach ($teamIdsByCode as $accessCode => $teamId)
		{
			if (!isset($existingTeamIds[$teamId]) || !isset($visibleTeamIds[$teamId]))
			{
				$deniedCodes[] = $accessCode;
			}
		}

		return $deniedCodes;
	}

	/**
	 * The existence half of the answer, asked apart from the access half so a code no team answers is refused
	 * exactly as a hidden one, with or without a user to judge for.
	 *
	 * @param int[] $teamIds
	 * @return array<int, true>
	 */
	private function findExistingTeamIds(array $teamIds): array
	{
		if (empty($teamIds))
		{
			return [];
		}

		$rows = NodeTable::query()
			->setSelect(['ID', 'TYPE'])
			->whereIn('ID', array_values(array_unique($teamIds)))
			->fetchAll()
		;

		$existingTeamIds = [];
		foreach ($rows as $row)
		{
			if (NodeEntityType::tryFrom((string)$row['TYPE']) === NodeEntityType::TEAM)
			{
				$existingTeamIds[(int)$row['ID']] = true;
			}
		}

		return $existingTeamIds;
	}

	private function canEditNodeCollabs(int $nodeId, int $initiatorId, bool $withChildNodes): bool
	{
		$scopeKey = (int)$withChildNodes;
		if (isset($this->nodeCollabEditAccessCache[$initiatorId][$nodeId][$scopeKey]))
		{
			return $this->nodeCollabEditAccessCache[$initiatorId][$nodeId][$scopeKey];
		}

		$accessInfo = StructureAction::EditCollabAction->getAccessInfoByEntityType(NodeEntityType::TEAM);
		$chatList = ChatListModel::createFromId($nodeId)
			->setIdsArray(['withChildren' => $withChildNodes])
		;

		return $this->nodeCollabEditAccessCache[$initiatorId][$nodeId][$scopeKey] =
			StructureAccessController::getInstance($initiatorId)->check($accessInfo->actionId, $chatList)
		;
	}

	/**
	 * The scope is read from the same protocol that classified the code, so the classification of an incoming
	 * code and the NodeRelation written for it can never disagree on whether child nodes are included.
	 */
	private function isRecursiveAccessCode(string $accessCode): bool
	{
		return $this->memberEntityMapper->fromAccessCode($accessCode)?->withChildNodes === true;
	}

	private function makeNodeAccessError(): Error
	{
		return new Error(
			Loc::getMessage('SONET_V2_STRUCTURE_NODE_ACCESS_DENIED') ?? self::ERROR_NODE_ACCESS_DENIED,
			self::ERROR_NODE_ACCESS_DENIED,
		);
	}

	private function makeNodeNotFoundError(): Error
	{
		return new Error(
			Loc::getMessage('SONET_V2_STRUCTURE_NODE_NOT_FOUND') ?? self::ERROR_NODE_NOT_FOUND,
			self::ERROR_NODE_NOT_FOUND,
		);
	}

	private function makeTeamsNotAvailableError(): Error
	{
		return new Error(
			Loc::getMessage('SONET_V2_STRUCTURE_TEAMS_NOT_AVAILABLE') ?? self::ERROR_TEAMS_NOT_AVAILABLE,
			self::ERROR_TEAMS_NOT_AVAILABLE,
		);
	}

	private function unlinkNodeRelations(array $accessCodes, int $collabId): void
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		// \Bitrix\HumanResources\Service\NodeRelationService::unlinkEntityFromNodeByAccessCode
		// throws TypeError when a relation is absent.
		// Keep only codes that are actually linked.
		$codesToUnlink = array_intersect($accessCodes, $this->getNodeRelationAccessCodes($collabId));
		if (empty($codesToUnlink))
		{
			return;
		}

		// Same reasoning as linkNodes: no batch accessCode -> nodeId resolver in HR.
		$nodeRelationService = Container::getNodeRelationService();
		foreach ($codesToUnlink as $accessCode)
		{
			$nodeRelationService->unlinkEntityFromNodeByAccessCode(
				$accessCode,
				RelationEntityType::COLLAB,
				$collabId,
			);
		}
	}

	/**
	 * The legacy UF_SG_DEPT channel belongs to the old department model only, so team codes are filtered out here
	 * and never reach the cleanup queue.
	 */
	private function unlinkLegacyDepartments(array $accessCodes, int $collabId, int $initiatorId): void
	{
		$departmentIdsToUnlink =
			$this->memberEntityMapper
				->fromAccessCodes($accessCodes)
				->filterByType(MemberEntityType::Department)
				->getIds()
		;

		if (empty($departmentIdsToUnlink))
		{
			return;
		}

		$departmentIdsToUnlinkMap = array_fill_keys($departmentIdsToUnlink, true);
		foreach ($this->getLegacyDepartments($collabId, resolveNames: false) as $department)
		{
			if (!isset($departmentIdsToUnlinkMap[$department->getId()]))
			{
				continue;
			}

			// StructureSyncService depends on this service, so it cannot be constructor-injected here without a cycle
			Internal\DI\Container::getInstance()
				->getStructureSyncService()
				->enqueueLegacyDepartmentDeleted($collabId, $department->getId(), $initiatorId)
			;
		}
	}

	/**
	 * An access code addresses one node, so the codes stay distinct even when two linked nodes share one code:
	 * a department created in the new structure has no code of its own yet and borrows the DR{id} shape.
	 *
	 * @return string[] Access codes of the structure nodes linked to collab: departments (D123, DR456) and teams
	 */
	public function getLinkedNodeAccessCodes(int $collabId): array
	{
		return array_values(array_unique($this->memberEntityMapper->toAccessCodes(
			$this->getLinkedNodes($collabId, resolveLegacyNames: false),
		)));
	}

	public function getLinkedNodes(int $collabId, bool $resolveLegacyNames = true): MemberEntityCollection
	{
		$nodesByKey = [];
		foreach ($this->getLegacyDepartments($collabId, $resolveLegacyNames) as $department)
		{
			$nodesByKey[$this->buildLegacyDepartmentKey((int)$department->getId())] = $department;
		}

		foreach ($this->getNodeRelationEntries($collabId) as $entry)
		{
			if ($entry['legacyKey'] !== null)
			{
				unset($nodesByKey[$entry['legacyKey']]);
			}

			$nodesByKey[$entry['key']] = $entry['member'];
		}

		return new MemberEntityCollection(...array_values($nodesByKey));
	}

	private function buildLegacyDepartmentKey(int $departmentId): string
	{
		return self::LEGACY_DEPARTMENT_KEY_PREFIX . $departmentId;
	}

	private function buildScopedLegacyDepartmentKey(int $departmentId, bool $withChildNodes): string
	{
		return $this->buildLegacyDepartmentKey($departmentId) . ':' . (int)$withChildNodes;
	}

	private function buildStructureNodeKey(int $nodeId, bool $withChildNodes): string
	{
		return self::STRUCTURE_NODE_KEY_PREFIX . $nodeId . ':' . (int)$withChildNodes;
	}

	/**
	 * @return list<array{key: string, legacyKey: ?string, member: MemberEntity}>
	 */
	private function getNodeRelationEntries(int $collabId): array
	{
		$entries = [];

		foreach ($this->findNodeRelations($collabId) as $relation)
		{
			$member = $this->departmentRelationMapper->map($relation);
			if ($member === null)
			{
				continue;
			}
			$legacyDepartmentId = $this->departmentRelationMapper->findLegacyDepartmentId($relation);

			$entries[] = [
				'key' => $legacyDepartmentId === null
					? $this->buildStructureNodeKey((int)$member->getId(), $member->withChildNodes === true)
					: $this->buildScopedLegacyDepartmentKey(
						$legacyDepartmentId,
						$member->withChildNodes === true,
					),
				'legacyKey' => $legacyDepartmentId === null
					? null
					: $this->buildLegacyDepartmentKey($legacyDepartmentId),
				'member' => $member,
			];
		}

		return $entries;
	}

	private function getNodeRelationAccessCodes(int $collabId): array
	{
		return $this->memberEntityMapper->toAccessCodes($this->getNodeRelationMembers($collabId));
	}

	/**
	 * Members backed by an HR node relation: departments and teams, excluding the legacy UF_SG_DEPT channel
	 */
	private function getNodeRelationMembers(int $collabId): MemberEntityCollection
	{
		$nodes = new MemberEntityCollection();

		foreach ($this->getNodeRelationEntries($collabId) as $entry)
		{
			$nodes->add($entry['member']);
		}

		return $nodes;
	}

	/**
	 * Departments attached to a project the legacy way (the UF_SG_DEPT field) have no HR node relation
	 */
	private function getLegacyDepartments(int $collabId, bool $resolveNames = true): MemberEntityCollection
	{
		$departments = new MemberEntityCollection();
		$departmentIds = $this->normalizeLegacyDepartmentIds(
			$this->workgroupRepository->getLegacyDepartmentIds($collabId),
		);
		$departmentNames = $resolveNames
			? $this->departmentProvider->getNamesByLegacyIds($departmentIds)
			: [];

		foreach ($departmentIds as $departmentId)
		{
			$departments->add(new MemberEntity(
				id: $departmentId,
				type: MemberEntityType::Department,
				// Legacy UF_SG_DEPT membership always includes sub-departments
				withChildNodes: true,
				name: $departmentNames[$departmentId] ?? null,
			));
		}

		return $departments;
	}

	/**
	 * @param array<int|string, mixed> $departmentIds
	 * @return int[]
	 */
	private function normalizeLegacyDepartmentIds(array $departmentIds): array
	{
		$normalizedIds = [];
		foreach ($departmentIds as $departmentId)
		{
			if (is_string($departmentId) && preg_match('/^-?\d+$/D', $departmentId) === 1)
			{
				$departmentId = (int)$departmentId;
			}

			if (!is_int($departmentId) || $departmentId <= 0)
			{
				continue;
			}

			$normalizedIds[$departmentId] = $departmentId;
		}

		return array_values($normalizedIds);
	}

	/**
	 * @return int[] chat ids linked to the given structure node
	 */
	public function getChatRelations(int $nodeId): array
	{
		if (isset($this->chatRelationsCache[$nodeId]))
		{
			return $this->chatRelationsCache[$nodeId];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$relations = Container::getNodeRelationRepository()
			->findRelationsByNodeIdAndRelationType($nodeId, RelationEntityType::CHAT)
		;

		$this->chatRelationsCache[$nodeId] = array_map(
			static fn(NodeRelation $relation): int => $relation->entityId,
			$relations->getValues(),
		);

		return $this->chatRelationsCache[$nodeId];
	}

	/**
	 * @param int[] $chatIds
	 */
	public function unlinkChatRelations(int $nodeId, array $chatIds, bool $withChildNodes): void
	{
		if (empty($chatIds) || !Loader::includeModule('humanresources'))
		{
			return;
		}

		$repository = Container::getNodeRelationRepository();
		foreach ($chatIds as $chatId)
		{
			$relation = $repository->getByNodeIdAndEntityTypeAndEntityIdAndWithChildNodes(
				nodeId: $nodeId,
				entityType: RelationEntityType::CHAT,
				entityId: $chatId,
				withChildNodes: $withChildNodes,
			);
			if ($relation !== null)
			{
				$repository->remove($relation);
			}
		}

		unset($this->chatRelationsCache[$nodeId]);
	}

	public function getUsersNotInOtherRelations(int $projectId, array $userIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return Container::getNodeRelationService()
			->getUsersNotInRelation(RelationEntityType::COLLAB, $projectId, $userIds)
		;
	}

	private function findNodeRelations(int $collabId): iterable
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return Container::getNodeRelationService()
			->findAllRelationsByEntityTypeAndEntityId(RelationEntityType::COLLAB, $collabId)
		;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Internals\Repository\Structure\NodeMemberRepository;
use Bitrix\HumanResources\Internals\Repository\Structure\NodeRepository;
use Bitrix\HumanResources\Internals\Repository\Structure\UserRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\User;
use Bitrix\HumanResources\Service\Container;
use CSite;
use CUser;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;

class UserService
{
	private const SEARCH_LIMIT = 30;
	private const MIN_TOKEN_SIZE = 2;

	private readonly NodeRepository $nodeRepository;
	private readonly NodeMemberRepository $nodeMemberRepository;
	private readonly UserRepository $userRepository;

	public function __construct()
	{
		$this->nodeRepository = InternalContainer::getNodeRepository();
		$this->nodeMemberRepository = InternalContainer::getNodeMemberRepository();
		$this->userRepository = InternalContainer::getUserRepository();
	}

	public function getUserById(int $userId): ?User
	{
		return $this->userRepository->getById($userId);
	}

	public function getUserName(User $user): string
	{
		return CUser::FormatName(
			CSite::GetNameFormat(false),
			[
				'LOGIN' => $user->login,
				'NAME' => $user->firstName,
				'LAST_NAME' => $user->lastName,
				'SECOND_NAME' => $user->secondName,
			],
			true,
			false,
		);
	}

	/**
	 * Search employees by name, optionally within a specific node.
	 *
	 * @param string $query
	 * @param int|null $nodeId
	 * @param int $limit
	 *
	 * @return \Bitrix\HumanResources\Item\User[]
	 */
	public function searchByName(string $query, ?int $nodeId = null, int $limit = self::SEARCH_LIMIT): array
	{
		if ($nodeId !== null)
		{
			return $this->searchInNode($nodeId, $query);
		}

		return $this->searchGlobal($query, $limit);
	}

	/**
	 * Get departments and teams for each user.
	 * Pass $viewerUserId to filter nodes by viewer's access permissions.
	 *
	 * @param \Bitrix\HumanResources\Item\User[] $users
	 * @param int|null $viewerUserId If set, only nodes visible to this user are returned
	 *
	 * @return array<int, array{departments: array, teams: array}>
	 */
	public function getNodesByUsers(array $users, ?int $viewerUserId = null): array
	{
		$entityIds = array_values(array_map(fn($user) => $user->id, $users));
		if (empty($entityIds))
		{
			return [];
		}

		$structureAction = $viewerUserId !== null ? StructureAction::ViewAction : null;

		$members = $this->nodeMemberRepository->findAllByEntityIds(
			entityIds: $entityIds,
			nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			structureAction: $structureAction,
			userId: $viewerUserId,
		);

		$nodeIds = array_unique($members->getNodeIds());
		$nodes = [];
		if (!empty($nodeIds))
		{
			$nodeCollection = $this->nodeRepository->findAllByIds(
				nodeIds: $nodeIds,
				nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			);
			foreach ($nodeCollection as $node)
			{
				$nodes[$node->id] = $node;
			}
		}

		$result = [];
		foreach ($entityIds as $entityId)
		{
			$result[$entityId] = ['departments' => [], 'teams' => []];
		}

		foreach ($members as $member)
		{
			$node = $nodes[$member->nodeId] ?? null;
			if ($node === null)
			{
				continue;
			}

			$entry = ['id' => $node->id, 'name' => $node->name];
			if ($node->type === NodeEntityType::DEPARTMENT)
			{
				$result[$member->entityId]['departments'][] = $entry;
			}
			else
			{
				$result[$member->entityId]['teams'][] = $entry;
			}
		}

		return $result;
	}

	/**
	 * @return \Bitrix\HumanResources\Item\User[]
	 */
	private function searchInNode(int $nodeId, string $query): array
	{
		$node = $this->nodeRepository->getById($nodeId);
		if ($node === null)
		{
			return [];
		}

		$userRepository = Container::getUserRepository();
		$userCollection = $userRepository->findByNodeAndSearchQuery($node, $query);

		$result = [];
		foreach ($userCollection as $user)
		{
			$result[] = $user;
		}

		return $result;
	}

	/**
	 * @return \Bitrix\HumanResources\Item\User[]
	 */
	private function searchGlobal(string $query, int $limit): array
	{
		$tokens = $this->expandSearchQuery($query);
		if (empty($tokens))
		{
			return [];
		}

		$searchFilter = Query::filter()->logic('or');
		foreach ($tokens as $token)
		{
			$searchFilter->whereMatch(
				'USER_INDEX.SEARCH_USER_CONTENT',
				Helper::matchAgainstWildcard(
					Content::prepareStringToken($token), '*', self::MIN_TOKEN_SIZE,
				),
			);
		}

		$baseQuery = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where($searchFilter)
			->registerRuntimeField(
				new Reference(
					'USER_INDEX',
					UserIndexTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setLimit($limit)
		;

		$baseQuery = $this->nodeMemberRepository->injectUserNodeSubquery($baseQuery, null);

		$rows = $baseQuery->exec()->fetchAll();

		$userService = Container::getUserService();
		$allUsers = [];
		foreach ($rows as $row)
		{
			$user = $userService->getUserById((int)$row['ID']);
			if ($user !== null)
			{
				$allUsers[$user->id] = $user;
			}
		}

		return $allUsers;
	}

	private function expandSearchQuery(string $query): array
	{
		$query = trim($query);
		$expanded = [$query];

		$words = preg_split('/\s+/', $query);
		if ($words === false)
		{
			return $expanded;
		}

		foreach ($words as $word)
		{
			if (mb_strlen($word) >= self::MIN_TOKEN_SIZE)
			{
				$expanded[] = $word;
			}
		}

		return array_unique($expanded);
	}
}

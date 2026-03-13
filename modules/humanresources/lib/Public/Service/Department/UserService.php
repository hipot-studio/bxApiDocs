<?php

namespace Bitrix\HumanResources\Public\Service\Department;

use Bitrix\HumanResources\Internals\Repository\Structure\NodeMemberRepository;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Public\Service\Node\UserService as NodeUserService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\HumanResources\Util\StructureHelper;

class UserService
{
	private NodeUserService $nodeUserService;
	private NodeMemberRepository $internalNodeMemberRepository;

	public function __construct()
	{
		$this->nodeUserService = PublicContainer::getUserService();
		$this->internalNodeMemberRepository = InternalContainer::getNodeMemberRepository();
	}

	/**
	 * Returns true if user is head or deputy of any department, excluding teams
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOfDepartment(int $userId): bool
	{
		$headMember = $this->nodeUserService->findByUserIdAndStructureRoles(
			$userId,
			[StructureRole::HEAD],
		);

		return $headMember !== null;
	}

	/**
	 * Returns true if user is head or deputy of any department, excluding teams
	 *
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		$headMember = $this->nodeUserService->findByUserIdAndStructureRoles(
			$userId,
			[StructureRole::HEAD, StructureRole::DEPUTY_HEAD],
		);

		return $headMember !== null;
	}

	public function getTotalEmployeeCount(): int
	{
		$structure = StructureHelper::getDefaultStructure();
		if (!$structure)
		{
			return 0;
		}

		$rootDepartment =  Container::getNodeRepository()->getRootNodeByStructureId($structure->id);
		if (!$rootDepartment)
		{
			return 0;
		}

		return $this->internalNodeMemberRepository->countUniqueUsersByNodeIdWithSubNodes($rootDepartment->id);
	}

	/**
	 * Returns the nearest department heads from the branch where the given user belongs
	 */
	public function getUserHeads(int $userId): NodeMemberCollection
	{
		try
		{
			return InternalContainer::getNodeMemberService()->getNodeMemberHeads(
				entityId: $userId,
			);
		}
		catch (\Throwable)
		{
			return new NodeMemberCollection();
		}
	}

	/**
	 * Filters user IDs, returning only those who are employees.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterEmployeeIds(array $userIds): array
	{
		$filteredIds = array_filter($userIds, static fn($id) => is_numeric($id) && (int)$id > 0);
		$intIds = array_map('intval', $filteredIds);

		if (empty($userIds))
		{
			return [];
		}

		return $this->internalNodeMemberRepository->getExistingEntityIds($intIds);
	}
}

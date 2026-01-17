<?php

namespace Bitrix\HumanResources\Public\Service\Department;

use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Public\Service\Container as PublicContainer;
use Bitrix\HumanResources\Public\Service\Node\UserService as NodeUserService;
use Bitrix\HumanResources\Internals\Repository\Structure\Node\NodeMemberRepository;
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
}

<?php

namespace Bitrix\Sign\Operation\Member;

use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Result\Operation\Member\DepartmentSyncMembersResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Hr\EntitySelector;
use Bitrix\Sign\Item\Hr\EntitySelector\Entity;
use Bitrix\Sign\Item\Hr\EntitySelector\EntityCollection;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Item\Member\SelectorEntityCollection;

class GetDepartmentSyncMembers implements Operation
{
	private readonly MemberService $memberService;

	public function __construct(
		private readonly SelectorEntityCollection $entities
	)
	{
		$this->memberService = Container::instance()->getMemberService();
	}

	public function launch(): DepartmentSyncMembersResult
	{
		$assigneeEntityType = '';
		$memberCollection = new MemberCollection();
		$departmentEntities = new EntityCollection();
		foreach ($this->entities as $member)
		{
			$entityType = EntitySelector\EntityType::fromEntityIdAndType(
				$member->entityId,
				$member->entityType,
			);

			if ($entityType->isDepartment())
			{
				$departmentEntities->add(Entity::createFromStrings(
					entityId: $member->entityId,
					entityType: $member->entityType,
				));
			}

			$role = $member->role ?? \Bitrix\Sign\Compatibility\Role::createByParty($member->party);
			if ($role === Role::ASSIGNEE)
			{
				$assigneeEntityType = $member->entityType;
			}

			$memberCollection->add($this->createUserPartyMember(
				$member->party,
				$entityType->getMemberEntityType($member->entityType),
				(int)$member->entityId,
				$role,
			));
		}

		return new DepartmentSyncMembersResult(
			members: $this->prepareSignersFromSignDocumentEntities($memberCollection),
			departments: $departmentEntities,
			assigneeEntityType: $assigneeEntityType,
		);
	}

	private function prepareSignersFromSignDocumentEntities(MemberCollection $memberCollection): MemberCollection
	{
		$newCollection = new MemberCollection();

		$signerUserIds = $this->memberService->getUserIdsForMembers($memberCollection->filterByRole(Role::SIGNER));

		/** @var Member $member */
		foreach ($memberCollection as $member)
		{
			if ($member->entityType !== EntityType::DOCUMENT)
			{
				$newCollection->add($member);
				continue;
			}

			$signers = $this->memberService->listByDocumentId($member->entityId)->filterByRole(Role::SIGNER);
			$signers = $this->memberService->filterFiredSigners($signers);

			/** @var Member $signer */
			foreach ($signers as $signer)
			{
				if (in_array($signer->entityId, $signerUserIds, true))
				{
					continue;
				}

				$signerUserIds[] = $signer->entityId;

				$role = $member->role ?? \Bitrix\Sign\Compatibility\Role::createByParty($member->party);
				$newCollection->add($this->createUserPartyMember(
					$member->party,
					$signer->entityType,
					$signer->entityId,
					$role,
				));
			}
		}

		return $newCollection;
	}

	private function createUserPartyMember(
		int $party,
		string $entityType,
		int $entityId,
		string $role,
	): Member
	{
		return new Member(
			party: $party,
			entityType: $entityType,
			entityId: $entityId,
			role: $role,
		);
	}
}
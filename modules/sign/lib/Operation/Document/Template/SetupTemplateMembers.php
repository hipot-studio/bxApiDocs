<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\HumanResources\StructureNodeService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;

class SetupTemplateMembers implements Operation
{
	private readonly MemberService $memberService;
	private readonly MemberRepository $memberRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly StructureNodeService $structureNodeService;

	public function __construct(
		private readonly Document $document,
		private readonly ?int $sendFromUserId = null,
		private readonly ?int $representativeUserId = null,
		private readonly ?MemberCollection $memberList = null,
	)
	{
		$this->memberService = Container::instance()->getMemberService();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->structureNodeService = Container::instance()->getHumanResourcesStructureNodeService();
	}

	public function launch(): Main\Result
	{
		$document = $this->document;
		$members = $this->getMemberList($document);
		$signerMember = $members->filter(fn (Member $member): bool => $member->role === Role::SIGNER)->getFirst();

		if ($signerMember === null)
		{
			return (new Main\Result())->addError(new Error('Signer member not found'));
		}

		$parties = [];

		foreach ($members as $member)
		{
			if ($member === null)
			{
				continue;
			}

			$parties[] = $member->party;

			$prepareDataResult = $this->prepareMemberData($member, $document, $signerMember);
			if (!$prepareDataResult->isSuccess())
			{
				return $prepareDataResult;
			}
		}

		if ($this->representativeUserId !== null)
		{
			$document->representativeId = $this->representativeUserId;
		}

		$result = $this->memberService->setupB2eMembers($document->uid, $members, $document->representativeId, true);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$document->parties = count(array_unique($parties));

		return $this->documentRepository->update($document);
	}

	private function getMemberList(Document $document): MemberCollection
	{
		$templateMembers = $this->memberRepository->listByDocumentIdExcludeRoles($document->id, Role::SIGNER);
		if ($document->initiatedByType === InitiatedByType::EMPLOYEE)
		{
			$templateMembers->add(
				new Member(
					party: 1,
					entityType: EntityType::USER,
					entityId: $this->sendFromUserId,
					role: Role::SIGNER,
				),
			);
		}

		if ($this->memberList === null)
		{
			return $templateMembers;
		}

		$signerList = $this->memberList->filter(fn (Member $member): bool => $member->role === Role::SIGNER);
		$isOnlySignersAdded = $this->memberList->count() === $signerList->count();

		$result = [];
		$currentParty = ($document->initiatedByType === InitiatedByType::EMPLOYEE) ? 2 : 1;
		foreach (Role::getAll() as $role)
		{
			$memberListByRole = $this->memberList->filter(fn (Member $member): bool => $member->role === $role);
			if ($memberListByRole->count() === 0 && ($isOnlySignersAdded || $role === Role::ASSIGNEE))
			{
				$memberListByRole = $templateMembers->filter(fn (Member $member): bool => $member->role === $role);
			}

			if ($memberListByRole->count() === 0)
			{
				continue;
			}

			foreach ($memberListByRole as $member)
			{
				if ($member === null)
				{
					continue;
				}

				$member->party = ($role === Role::SIGNER) ? $currentParty : $currentParty++;
			}

			$result = array_merge($result, $memberListByRole->toArray());
		}

		return new MemberCollection(...$result);
	}

	private function prepareMemberData(Member $member, Document $document, Member $firstSignerMember): Main\Result
	{
		$result = new Main\Result();
		$member->id = null;
		if ($member->entityType !== EntityType::ROLE)
		{
			return $result;
		}

		$roleId = (int)($member->role === Role::ASSIGNEE ? $document->representativeId : $member->entityId);
		if ($roleId < 1)
		{
			return $result->addError(new Error('Invalid role id'));
		}

		$userIdFromRole = $this->structureNodeService->getNearestUserIdByEmployeeUserIdAndRoleId(
			(int)$firstSignerMember->entityId,
			$roleId,
		);

		if ($userIdFromRole < 1)
		{
			return $result->addError(new Error('Can not find member with role'));
		}

		if ($member->role === Role::ASSIGNEE)
		{
			$document->representativeId = $userIdFromRole;
		}
		else
		{
			$member->entityId = $userIdFromRole;
		}

		$member->entityType = $member->role === Role::ASSIGNEE ? EntityType::COMPANY : EntityType::USER;

		return $result;
	}
}
<?php

namespace Bitrix\Sign\Operation\Rest;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\Integration\Rest\RestDocumentStatus;
use Bitrix\Sign\Type\Member\EntityType;

final class PrepareDocumentSendResponse implements Contract\Operation
{

	private Service\Integration\HumanResources\HcmLinkService $hcmLinkService;
	public Item\Api\Rest\SignDocument\SignDocumentResponse $responseData;

	public function __construct(
		private readonly Document $document,
		private readonly Item\MemberCollection $members,
		private readonly ?string $language
	)
	{
		$container = Container::instance();
		$this->hcmLinkService = $container->getHcmLinkService();
	}

	public function launch(): Main\Result
	{
		$documentMembers = [];
		foreach ($this->members as $member)
		{
			$employeeId = $member->employeeId;
			if ($member->entityType === EntityType::COMPANY)
			{
				$userId = $this->document->representativeId;
			}
			elseif ($member->entityType === EntityType::USER)
			{
				$userId = $member->entityId;
			}
			else
			{
				continue;
			}

			$employee = null;
			if ($employeeId) {
				//todo optimize to get all employees at once
				$employee = $this->hcmLinkService->getEmployeesByIds([$employeeId])->getFirst();
			}

			$memberState = new Item\Api\Rest\SignDocument\SignMemberState(
				code: RestDocumentStatus::getDocumentMemberStatusCode($member->status),
				name: RestDocumentStatus::getDocumentMemberStatusName($member->status, $this->language)
			);
			$memberData = new Item\Api\Rest\SignDocument\SignMemberData(
				employeeCode: $employee?->code,
				employeeId: $employee?->id,
				userId: $userId
			);
			$documentMembers[] = new Item\Api\Rest\SignDocument\SignDocumentMember(
				uid: $member->uid,
				role: $member->role,
				party: $member->party,
				user: $memberData,
				state: $memberState
			);
		}

		$this->responseData = new Item\Api\Rest\SignDocument\SignDocumentResponse(
			uid: $this->document->uid,
			state: new Item\Api\Rest\SignDocument\SignDocumentState(
				code: RestDocumentStatus::getDocumentStatusCode($this->document->status),
				name: RestDocumentStatus::getDocumentStatusName($this->document->status, $this->language),
			),
			members: $documentMembers
		);

		return new Main\Result();
	}

}

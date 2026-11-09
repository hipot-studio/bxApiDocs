<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Contract\Document\ManualFillingDecisionProvider;
use Bitrix\Sign\Item;
use Bitrix\Sign\Result\Service\Sign\Document\CompanyInitiatedPartyPlanResult;
use Bitrix\Sign\Result\Service\Sign\Document\ManualFillingDecisionResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Document\ManualFillingDecision;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Psr\Log\LoggerInterface;

// the refusal of a document with no creator is the refusal the registration already speaks,
// so its phrase is taken from there instead of a second wording of the same thing
Loc::loadMessages(__DIR__ . '/../DocumentService.php');

final class CompanyInitiatedPartyPlanService
{
	private const ERROR_INVALID_CREATED_BY_ID = 'INVALID_CREATED_BY_ID';

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly ManualFillingDecisionProvider $manualFillingDecisionProvider,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? Container::instance()->getLogger('Service');
	}

	public function plan(Item\Document $document, Item\MemberCollection $members): CompanyInitiatedPartyPlanResult
	{
		// A template carries the composition its author selected, and the same setup action saves it.
		// Rebuilding that composition would drop the selected editor for good, so a template keeps
		// the members it was given and only the documents sent from it get the reordered stages.
		if (
			$document->initiatedByType !== InitiatedByType::COMPANY
			|| $document->isTemplated()
			|| !Feature::instance()->isKedoStageReorderEnabled()
		)
		{
			return new CompanyInitiatedPartyPlanResult($members);
		}

		if ((int)$document->createdById < 1)
		{
			$result = new CompanyInitiatedPartyPlanResult($members);
			$result->addError(new Error(
				Loc::getMessage('SIGN_SERVICE_DOCUMENT_USER_NOT_FOUND') ?? '',
				self::ERROR_INVALID_CREATED_BY_ID,
			));

			return $result;
		}

		return $this->buildCompanyInitiatedPlan(
			$document,
			$members,
			$this->decideManualFilling($document),
		);
	}

	private function decideManualFilling(Item\Document $document): ManualFillingDecision
	{
		try
		{
			$result = $this->manualFillingDecisionProvider->decide($document);
			if (!$result instanceof ManualFillingDecisionResult)
			{
				$this->logger->error(
					'Invalid manual filling decision result',
					['documentId' => $document->id],
				);

				return ManualFillingDecision::ERROR;
			}

			return $result->decision;
		}
		catch (\Throwable)
		{
			$this->logger->error(
				'Failed to decide manual filling',
				['documentId' => $document->id],
			);

			return ManualFillingDecision::ERROR;
		}
	}

	private function buildCompanyInitiatedPlan(
		Item\Document $document,
		Item\MemberCollection $members,
		ManualFillingDecision $manualFillingDecision,
	): CompanyInitiatedPartyPlanResult
	{
		$result = new Item\MemberCollection();
		$party = 1;

		if ($manualFillingDecision !== ManualFillingDecision::NOT_REQUIRED)
		{
			$result->add($this->makeEditor($document, $party++));
		}

		foreach ([Role::REVIEWER, Role::ASSIGNEE] as $role)
		{
			$party = $this->addMembersAsSeparateParties($result, $members, $role, $party);
		}

		$this->addMembersToParty($result, $members, Role::SIGNER, $party);

		return new CompanyInitiatedPartyPlanResult($result, $manualFillingDecision);
	}

	private function makeEditor(Item\Document $document, int $party): Item\Member
	{
		return new Item\Member(
			documentId: $document->id,
			party: $party,
			entityType: EntityType::USER,
			entityId: (int)$document->createdById,
			role: Role::EDITOR,
			createdById: (int)$document->createdById,
		);
	}

	private function addMembersAsSeparateParties(
		Item\MemberCollection $result,
		Item\MemberCollection $members,
		string $role,
		int $party,
	): int
	{
		foreach ($members as $member)
		{
			if ($member->role !== $role)
			{
				continue;
			}

			$member = clone $member;
			$member->party = $party++;
			$result->add($member);
		}

		return $party;
	}

	private function addMembersToParty(
		Item\MemberCollection $result,
		Item\MemberCollection $members,
		string $role,
		int $party,
	): void
	{
		foreach ($members as $member)
		{
			if ($member->role !== $role)
			{
				continue;
			}

			$member = clone $member;
			$member->party = $party;
			$result->add($member);
		}
	}
}

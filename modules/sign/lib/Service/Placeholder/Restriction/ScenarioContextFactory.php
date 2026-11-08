<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentScenario;

class ScenarioContextFactory
{
	private readonly MemberRepository $memberRepository;

	public function __construct(?MemberRepository $memberRepository = null)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
	}

	public function fromDocument(Item\Document $document): ScenarioContext
	{
		return new ScenarioContext(
			scenario: $document->scenario,
			initiatedByType: $document->initiatedByType,
			isMassDocument: $this->resolveMassDocument($document),
			externalIdSourceType: $document->externalIdSourceType,
			externalDateCreateSourceType: $document->externalDateCreateSourceType,
			region: ScenarioContext::resolveRegion(),
			providerCode: $document->providerCode,
			hcmLinkCompanyId: $document->hcmLinkCompanyId,
		);
	}

	/**
	 * Mirrors the anti-leak criterion of Controllers\V1\Document\Blank\Block: a B2E document
	 * signed by more than one member of the last party is a mass (HR mass mailing) document.
	 * Returns null while the axis cannot be resolved yet.
	 */
	private function resolveMassDocument(Item\Document $document): ?bool
	{
		if (!DocumentScenario::isB2EScenario($document->scenario))
		{
			return false;
		}

		if ($document->id === null || $document->parties === null)
		{
			return null;
		}

		return $this->memberRepository->hasSeveralMembersInParty($document->id, $document->parties);
	}
}

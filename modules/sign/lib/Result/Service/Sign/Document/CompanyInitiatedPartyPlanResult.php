<?php

namespace Bitrix\Sign\Result\Service\Sign\Document;

use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Type\Document\ManualFillingDecision;

final class CompanyInitiatedPartyPlanResult extends Result
{
	public function __construct(
		public readonly MemberCollection $members,
		public readonly ?ManualFillingDecision $manualFillingDecision = null,
	)
	{
		parent::__construct();

		$this->setData([
			'members' => $this->members,
			'manualFillingDecision' => $this->manualFillingDecision,
		]);
	}
}

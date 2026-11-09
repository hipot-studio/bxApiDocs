<?php

namespace Bitrix\Sign\Result\Service\Sign\Document;

use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Type\Document\ManualFillingDecision;

final class ManualFillingDecisionResult extends Result
{
	public readonly ManualFillingDecision $decision;

	/**
	 * @var list<string>
	 */
	public readonly array $missingFieldIds;

	public readonly bool $isCompleted;

	/**
	 * @param list<string> $missingFieldIds
	 */
	public function __construct(ManualFillingDecision $decision, array $missingFieldIds = [])
	{
		parent::__construct();

		$this->decision = $decision;
		$this->missingFieldIds = array_values($missingFieldIds);
		$this->isCompleted = $decision !== ManualFillingDecision::PENDING;

		$this->setData([
			'decision' => $this->decision,
			'missingFieldIds' => $this->missingFieldIds,
			'isCompleted' => $this->isCompleted,
		]);
	}
}

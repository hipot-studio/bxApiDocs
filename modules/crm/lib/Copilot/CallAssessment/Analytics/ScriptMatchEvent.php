<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class ScriptMatchEvent extends AbstractBuilder
{
	private int $callAssessmentId = 0;
	private ?string $confidenceScore = null;

	public function setCallAssessmentId(int $callAssessmentId): self
	{
		$this->callAssessmentId = $callAssessmentId;

		return $this;
	}

	public function setConfidenceScore(string $confidenceScore): self
	{
		$this->confidenceScore = $confidenceScore;

		return $this;
	}

	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	protected function buildCustomData(): array
	{
		if ($this->callAssessmentId > 0)
		{
			$this->setP2('scriptId', (string)$this->callAssessmentId);
		}

		if ($this->confidenceScore !== null && $this->confidenceScore !== '')
		{
			$this->setP3('confidenceScore', $this->confidenceScore);
		}

		return [
			'category' => Dictionary::CATEGORY_SPEECH_ANALYTICS,
			'event' => Dictionary::EVENT_SCRIPT_MATCH,
			'type' => Dictionary::TYPE_SCRIPT,
		];
	}
}

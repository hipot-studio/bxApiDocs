<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CallScoringEvent extends AbstractBuilder
{
	private int $callAssessmentId = 0;
	private ?string $callScore = null;
	private ?int $stepDurationSeconds = null;
	private int $activityId = 0;

	public function setCallAssessmentId(int $callAssessmentId): self
	{
		$this->callAssessmentId = $callAssessmentId;

		return $this;
	}

	public function setCallScore(string $callScore): self
	{
		$this->callScore = $callScore;

		return $this;
	}

	public function setStepDurationSeconds(int $seconds): self
	{
		$this->stepDurationSeconds = $seconds;

		return $this;
	}

	public function setActivityId(int $activityId): self
	{
		$this->activityId = $activityId;

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

		if ($this->callScore !== null && $this->callScore !== '')
		{
			$this->setP3('callScore', $this->callScore);
		}

		if ($this->stepDurationSeconds !== null && $this->stepDurationSeconds >= 0)
		{
			$this->setP4('stepDuration', (string)$this->stepDurationSeconds);
		}

		if ($this->activityId > 0)
		{
			$this->setP5('idCall', (string)$this->activityId);
		}

		return [
			'category' => Dictionary::CATEGORY_SPEECH_ANALYTICS,
			'event' => Dictionary::EVENT_CALL_SCORING,
			'type' => Dictionary::TYPE_COPILOT,
		];
	}
}

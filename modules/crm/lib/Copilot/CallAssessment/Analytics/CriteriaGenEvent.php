<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CriteriaGenEvent extends AbstractBuilder
{
	private int $callAssessmentId = 0;
	private int $criteriaCount = 0;
	private bool $isManualLaunch = false;

	public function setCallAssessmentId(int $callAssessmentId): self
	{
		$this->callAssessmentId = $callAssessmentId;

		return $this;
	}

	public function setCriteriaCount(int $criteriaCount): self
	{
		$this->criteriaCount = $criteriaCount;

		return $this;
	}

	public function setIsManualLaunch(bool $isManualLaunch): self
	{
		$this->isManualLaunch = $isManualLaunch;

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

		if ($this->criteriaCount > 0)
		{
			$this->setP3('criteriaCount', (string)$this->criteriaCount);
		}

		return [
			'category' => Dictionary::CATEGORY_SPEECH_ANALYTICS,
			'event' => Dictionary::EVENT_CRITERIA_GEN,
			'type' => $this->isManualLaunch ? Dictionary::TYPE_MANUAL : Dictionary::TYPE_AUTO,
		];
	}
}

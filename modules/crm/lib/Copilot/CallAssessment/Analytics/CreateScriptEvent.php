<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class CreateScriptEvent extends AbstractBuilder
{
	private int $callAssessmentId = 0;
	private bool $isManualLaunch = false;

	public function setCallAssessmentId(int $callAssessmentId): self
	{
		$this->callAssessmentId = $callAssessmentId;

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

		return [
			'category' => Dictionary::CATEGORY_SPEECH_ANALYTICS,
			'event' => Dictionary::EVENT_CREATE_SCRIPT,
			'type' => $this->isManualLaunch ? Dictionary::TYPE_MANUAL : Dictionary::TYPE_AUTO,
		];
	}
}

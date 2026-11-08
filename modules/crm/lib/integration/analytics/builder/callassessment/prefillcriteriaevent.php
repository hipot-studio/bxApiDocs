<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\CallAssessment;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;

final class PrefillCriteriaEvent extends AbstractBuilder
{
	protected function getTool(): string
	{
		return Dictionary::TOOL_CRM;
	}

	protected function buildCustomData(): array
	{
		return [
			'category' => Dictionary::CATEGORY_SPEECH_ANALYTICS,
			'event' => Dictionary::EVENT_PREFILL_CRITERIA,
		];
	}
}

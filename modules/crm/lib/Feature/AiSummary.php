<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Main\Localization\Loc;

/**
 * The switch of the summary of a CRM entity told by the AI assistant.
 *
 * One switch for every type the summary answers for - a contact, a company and a deal: the measure of
 * availability is one, and a type of its own would have to be closed twice.
 *
 * It closes the tool, the set it belongs to and the agent at once, because all three ask one measure
 * of availability and the switch is asked inside it.
 *
 * The values are the inherited ones on purpose: disabling removes the option, so a portal that never
 * touched the switch reads as a portal with the feature off.
 *
 * @see \Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability
 */
final class AiSummary extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_AI_SUMMARY_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Experimental::getInstance();
	}

	protected function getOptionName(): string
	{
		return 'use_ai_summary';
	}
}

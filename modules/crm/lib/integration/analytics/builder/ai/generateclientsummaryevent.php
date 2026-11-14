<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Dictionary;

final class GenerateClientSummaryEvent extends AIBaseEvent
{
	protected function getEvent(): string
	{
		return Dictionary::EVENT_GENERATE_CLIENT_SUMMARY;
	}
}

<?php declare(strict_types=1);

namespace Bitrix\AI\SharePrompt\Events;

class SaveAnalyticEvent extends BaseAnalyticEvent
{
	public function getEventName(): string
	{
		return 'save';
	}
}

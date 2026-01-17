<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage;

// This event is used to track when stage changes its position in the funnel, not rename/recolor
final class UpdateEvent extends StageBaseEvent
{
	private const EVENT_NAME = 'update';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

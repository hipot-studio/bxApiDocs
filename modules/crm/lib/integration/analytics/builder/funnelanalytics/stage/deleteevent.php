<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage;

final class DeleteEvent extends StageBaseEvent
{
	private const EVENT_NAME = 'delete';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

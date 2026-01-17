<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage;

final class CreateEvent extends StageBaseEvent
{
	private const EVENT_NAME = 'create';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

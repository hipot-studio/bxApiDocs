<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel;

final class DeleteEvent extends FunnelBaseEvent
{
	private const EVENT_NAME = 'delete';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

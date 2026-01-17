<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Tunnel;

final class DeleteEvent extends TunnelBaseEvent
{
	private const EVENT_NAME = 'delete';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel;

final class RenameEvent extends FunnelBaseEvent
{
	private const EVENT_NAME = 'rename';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Funnel;

final class OpenSliderEvent extends FunnelBaseEvent
{
	private const EVENT_NAME = 'view';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

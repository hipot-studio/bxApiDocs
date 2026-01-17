<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage;

final class RenameEvent extends StageBaseEvent
{
	private const EVENT_NAME = 'rename';

	public function getEventName(): string
	{
		return self::EVENT_NAME;
	}
}

<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage;

use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\FunnelAnalyticsBaseEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;

abstract class StageBaseEvent extends FunnelAnalyticsBaseEvent
{
	abstract public function getEventName(): string;

	public function __construct(
		?string $section = null,
		?string $subSection = null,
		?int $count = null,
	)
	{
		parent::__construct($section, $subSection);

		if ($count !== null)
		{
			$this->setP3('count', $count);
		}
	}

	protected function getType(): string
	{
		return Dictionary::TYPE_STAGE;
	}
}

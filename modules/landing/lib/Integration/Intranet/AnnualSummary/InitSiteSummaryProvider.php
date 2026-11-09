<?php

namespace Bitrix\Landing\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;

class InitSiteSummaryProvider
{
	public static function handle(OnInitSummaryProvidersEvent $event): void
	{
		if (!class_exists(SiteSummaryProvider::class))
		{
			return;
		}
		$provider = new SiteSummaryProvider($event->getFrom(), $event->getTo());
		$event->addProvider($provider);
	}
}

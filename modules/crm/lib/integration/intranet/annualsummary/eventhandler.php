<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;

final class EventHandler
{
	public static function onInitSummaryProviders(OnInitSummaryProvidersEvent $event): void
	{
		if (class_exists(WebFormSummaryProvider::class))
		{
			$event->addProvider(new WebFormSummaryProvider($event->getFrom(), $event->getTo()));
		}

		self::onInitClosedDealsSummaryProvider($event);
	}

	public static function onInitClosedDealsSummaryProvider(OnInitSummaryProvidersEvent $event): void
	{
		if (class_exists(ClosedDealsSummaryProvider::class))
		{
			$event->addProvider(new ClosedDealsSummaryProvider($event->getFrom(), $event->getTo()));
		}
	}
}

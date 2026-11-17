<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\EventHandler\OnInitSummaryProviders;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider\CreatedAudioTasksSummaryProvider;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider\MostProductiveMonthSummaryProvider;

class CreateProvider
{
	public static function handle(OnInitSummaryProvidersEvent $event): void
	{
		if (class_exists(CreatedAudioTasksSummaryProvider::class))
		{
			$event->addProvider(new CreatedAudioTasksSummaryProvider($event->getFrom(), $event->getTo()));
		}

		if (class_exists(MostProductiveMonthSummaryProvider::class))
		{
			$event->addProvider(new MostProductiveMonthSummaryProvider($event->getFrom(), $event->getTo()));
		}
	}
}

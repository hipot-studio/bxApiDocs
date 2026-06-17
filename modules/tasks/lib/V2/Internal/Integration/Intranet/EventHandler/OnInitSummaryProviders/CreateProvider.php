<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\EventHandler\OnInitSummaryProviders;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Provider\AnnualSummaryProvider;

class CreateProvider
{
	public static function handle(OnInitSummaryProvidersEvent $event): void
	{
		if (!class_exists(AnnualSummaryProvider::class))
		{
			return;
		}
		$provider = new AnnualSummaryProvider($event->getFrom(), $event->getTo());
		$event->addProvider($provider);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Intranet\EventHandler;

use Bitrix\AI\Integration\Intranet\AnnualSummary\ChatRequestsProvider;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Main\Loader;

/**
 * Declares the BitrixGPT metrics of the annual summary; intranet picks the users to count.
 */
final class AnnualSummaryProvidersHandler
{
	public static function handle(OnInitSummaryProvidersEvent $event): void
	{
		// The provider implements an intranet interface, so the module must be loaded to declare it.
		if (!Loader::includeModule('intranet'))
		{
			return;
		}

		$event->addProvider(new ChatRequestsProvider($event->getFrom(), $event->getTo()));
	}
}

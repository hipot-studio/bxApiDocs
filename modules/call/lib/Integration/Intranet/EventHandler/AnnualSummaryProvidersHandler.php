<?php

declare(strict_types=1);

namespace Bitrix\Call\Integration\Intranet\EventHandler;

use Bitrix\Call\Integration\Intranet\AnnualSummary\ParsedCallsProvider;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Main\Loader;

/**
 * Declares the BitrixGPT call metric of the annual summary; intranet picks the users to count.
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

		$event->addProvider(new ParsedCallsProvider($event->getFrom(), $event->getTo()));
	}
}

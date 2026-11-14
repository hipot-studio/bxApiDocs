<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\EventHandler;

use Bitrix\Im\V2\Integration\Intranet\AnnualSummary\ChannelProvider;
use Bitrix\Im\V2\Integration\Intranet\AnnualSummary\MessageIdRangeResolver;
use Bitrix\Im\V2\Integration\Intranet\AnnualSummary\MessageProvider;
use Bitrix\Im\V2\Integration\Intranet\AnnualSummary\ReactionProvider;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Main\Loader;

/**
 * Handler for the intranet::onInitSummaryProviders event.
 *
 * Announces the im metrics of the annual summary; the users to count are chosen by intranet.
 */
final class AnnualSummaryProvidersHandler
{
	public static function onInitSummaryProviders(OnInitSummaryProvidersEvent $event): void
	{
		// The providers implement an intranet interface, so the module must be loaded to declare them.
		if (!Loader::includeModule('intranet'))
		{
			return;
		}

		$from = $event->getFrom();
		$to = $event->getTo();

		// One resolver for every provider that needs the bounds: they share its memo within a step.
		$idRangeResolver = new MessageIdRangeResolver();

		$event->addProvider(new ChannelProvider($from, $to));
		$event->addProvider(new MessageProvider($from, $to, $idRangeResolver));
		$event->addProvider(new ReactionProvider($from, $to, $idRangeResolver));
	}
}

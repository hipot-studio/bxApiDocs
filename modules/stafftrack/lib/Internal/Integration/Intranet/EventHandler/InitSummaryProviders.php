<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Intranet\EventHandler;

use Bitrix\StaffTrack\Internal\Integration\Intranet\AnnualSummary\CheckInSummaryProvider;

final class InitSummaryProviders
{
	public static function onInitSummaryProviders(
		\Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent $event,
	): void
	{
		$event->addProvider(new CheckInSummaryProvider($event->getFrom(), $event->getTo()));
	}
}

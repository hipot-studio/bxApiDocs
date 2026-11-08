<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\EventHandler;

use Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary\AnnualSummaryProvider;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;

/**
 * Hands the provider of bizproc to the annual summary agent. The provider is handed over on every run,
 * whatever the feature flag says: the agent saves the ids of the providers on its first step, and an id
 * left there with no provider behind it finishes the run of the whole portal early. Whether the numbers
 * are counted is decided by the provider itself, inside provide().
 */
final class InitSummaryProviders
{
	public static function onInitSummaryProviders(OnInitSummaryProvidersEvent $event): void
	{
		// Without intranet the provider file returns before declaring the class, so class_exists()
		// is the other half of the pair with the early return of AnnualSummaryProvider.
		if (!class_exists(AnnualSummaryProvider::class))
		{
			return;
		}

		$event->addProvider(new AnnualSummaryProvider($event->getFrom(), $event->getTo()));
	}
}

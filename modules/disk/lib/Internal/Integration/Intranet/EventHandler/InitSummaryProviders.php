<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Intranet\EventHandler;

use Bitrix\Disk\Internal\Integration\Intranet\AnnualSummary\BoardCreationProvider;
use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;

/**
 * Hands the provider of disk to the annual summary agent on every run: the agent saves the ids of the
 * providers on its first step, and an id left there with no provider behind it finishes the run of the
 * whole portal early. Whether a batch is answered is decided by the provider itself, inside provide().
 */
final class InitSummaryProviders
{
	public static function onInitSummaryProviders(OnInitSummaryProvidersEvent $event): void
	{
		// Without intranet the provider file returns before declaring the class, so class_exists() is
		// the other half of the pair with the early return of BoardCreationProvider.
		if (!class_exists(BoardCreationProvider::class))
		{
			return;
		}

		$event->addProvider(new BoardCreationProvider($event->getFrom(), $event->getTo()));
	}
}

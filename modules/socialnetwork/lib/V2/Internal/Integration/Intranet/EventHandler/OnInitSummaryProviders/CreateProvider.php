<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\EventHandler\OnInitSummaryProviders;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\Provider\AiProjectsSummaryProvider;
use Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\Provider\LegacyProjectsAndCollabsSummaryProvider;
use Bitrix\Socialnetwork\V2\Internal\Repository\AnnualSummaryRepository;

class CreateProvider
{
	public function __construct(
		private readonly AnnualSummaryRepository $repository,
	)
	{
	}

	public function handle(OnInitSummaryProvidersEvent $event): void
	{
		$from = $event->getFrom();
		$to = $event->getTo();

		if (class_exists(LegacyProjectsAndCollabsSummaryProvider::class))
		{
			$event->addProvider(
				new LegacyProjectsAndCollabsSummaryProvider($from, $to, $this->repository),
			);
		}

		if (class_exists(AiProjectsSummaryProvider::class))
		{
			$event->addProvider(
				new AiProjectsSummaryProvider($from, $to, $this->repository),
			);
		}
	}
}

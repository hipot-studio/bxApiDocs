<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\EventHandler\OnInitSummaryProviders;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Repository\AnnualSummaryRepository;

if (!Loader::includeModule('intranet'))
{
	return;
}

class CreateProviderEventHandler
{
	public static function handle(OnInitSummaryProvidersEvent $event): void
	{
		(new CreateProvider(new AnnualSummaryRepository()))->handle($event);
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet\EventHandler;

use Bitrix\Intranet\Public\Event\OnCollectSidePanelBindingsEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class CollectSidePanelBindings
{
	private const SIDEPANEL_BINDER_EXTENSION = 'vibecodeconnector.sidepanel-binder';

	public static function handle(OnCollectSidePanelBindingsEvent $event): void
	{
		$availabilityService = ServiceLocator::getInstance()->get(AvailabilityService::class);

		if ($availabilityService->isAvailableForUser((int)CurrentUser::get()->getId()))
		{
			$event->addExtension(self::SIDEPANEL_BINDER_EXTENSION);
		}
	}
}

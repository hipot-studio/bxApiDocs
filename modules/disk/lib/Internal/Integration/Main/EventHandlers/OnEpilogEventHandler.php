<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Main\EventHandlers;

use Bitrix\Disk\Public\Service\DockedUpload\PortalMountPolicy;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\UI\Extension;

/**
 * Mounts the docked uploader entry point on the portal pages the policy lets it onto.
 *
 * Runs on every hit that reaches the epilog, manual runs of the epilog included; every rule lives in
 * {@see PortalMountPolicy}. A failed verdict leaves the page without the widget instead of breaking it.
 */
class OnEpilogEventHandler
{
	private const ENTRY_POINT_EXTENSION = 'disk.docked-uploader.portal';

	public static function handle(): void
	{
		try
		{
			$request = Context::getCurrent()?->getRequest();
			if (!$request instanceof HttpRequest)
			{
				return;
			}

			$userId = (int)CurrentUser::get()->getId();
			if (!PortalMountPolicy::getInstance()->shouldMountOnPortal($request, $userId))
			{
				return;
			}

			Extension::load(self::ENTRY_POINT_EXTENSION);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}

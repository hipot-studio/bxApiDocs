<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Internal\Integration\Call\Sync;
use Bitrix\Intranet\Internal\Integration\Ui\CopilotService;

class TeamWorkMenuItems
{
	public static function getIds(): array
	{
		$items = [
			'menu_im_messenger',
			'menu_im_collab',
			'menu_live_feed',
			'menu_calendar',
			'menu_documents',
			'menu_boards',
			'menu_files',
			'menu_external_mail',
			'menu_all_groups',
			'menu_all_spaces',
		];

		if (CopilotService::shouldShowInLeftMenu())
		{
			array_splice($items, 1, 0, ['menu_im_copilot']);
		}

		if (Sync::isAvailable())
		{
			array_splice($items, 3, 0, ['menu_sync']);
		}

		return $items;
	}
}

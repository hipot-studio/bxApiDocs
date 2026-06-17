<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Intranet\Internal\Integration\Ui\CopilotService;

class CopilotMenuConverter
{
	public function convertForUser(int $userId): void
	{
		if (!CopilotService::shouldShowInLeftMenu())
		{
			return;
		}

		$menu = \CUserOptions::GetOption('intranet', 'left_menu_sorted_items_s1', null, $userId);
		if (!is_array($menu) || !$this->shouldInsertCopilot($menu))
		{
			return;
		}

		\CUserOptions::SetOption('intranet', 'left_menu_sorted_items_old_copilot_s1', $menu, false, $userId);
		\CUserOptions::SetOption('intranet', 'left_menu_sorted_items_s1', $this->insertCopilot($menu), false, $userId);
		CacheProvider::deleteUserCache($userId);
	}

	private function shouldInsertCopilot(array $menu): bool
	{
		if (
			!isset($menu['show']['menu_teamwork'])
			|| !is_array($menu['show']['menu_teamwork'])
		)
		{
			return false;
		}

		$teamworkItems = $menu['show']['menu_teamwork'];
		$messengerPosition = array_search('menu_im_messenger', $teamworkItems, true);
		$copilotPosition = array_search('menu_im_copilot', $teamworkItems, true);

		if ($messengerPosition === false)
		{
			return false;
		}

		if ($copilotPosition === false)
		{
			return true;
		}

		return $copilotPosition !== ($messengerPosition + 1);
	}

	private function insertCopilot(array $menu): array
	{
		$teamworkItems = $menu['show']['menu_teamwork'];
		$teamworkItems = array_values(
			array_filter(
				$teamworkItems,
				static fn ($item): bool => $item !== 'menu_im_copilot',
			)
		);

		$messengerPosition = array_search('menu_im_messenger', $teamworkItems, true);
		if ($messengerPosition === false)
		{
			return $menu;
		}

		array_splice($teamworkItems, $messengerPosition + 1, 0, ['menu_im_copilot']);
		$menu['show']['menu_teamwork'] = $teamworkItems;

		return $menu;
	}
}

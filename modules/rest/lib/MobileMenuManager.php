<?php

namespace Bitrix\Rest;

class MobileMenuManager
{
	public static function onMobileMenuStructureBuilt(array $menu, $context): array
	{
		if (!($context instanceof \Bitrix\Mobile\Context))
		{
			return $menu;
		}

		if ($context->extranet === false && $context->isCollaber === false)
		{
			$marketItems = self::prepareMarketItems();
			$menu = self::addMenuItems($menu, 'marketplace', $marketItems);
		}

		return $menu;
	}

	/**
	 * @param array $menu
	 * @param string $sectionCode
	 * @param array $items
	 * @return array
	 */
	public static function addMenuItems(array $menu, string $sectionCode, array $items): array
	{
		if (empty($items))
		{
			return $menu;
		}

		$sectionIndex = null;
		foreach ($menu as $index => $section)
		{
			if (is_array($section) && isset($section['code']) && $section['code'] === $sectionCode)
			{
				$sectionIndex = $index;
				break;
			}
		}

		if ($sectionIndex !== null)
		{
			if (!isset($menu[$sectionIndex]['items']) || !is_array($menu[$sectionIndex]['items']))
			{
				$menu[$sectionIndex]['items'] = [];
			}

			$menu[$sectionIndex]['items'] = array_merge(
				$menu[$sectionIndex]['items'],
				$items
			);
		}

		return $menu;
	}

	private static function prepareMarketItems(): array
	{
		global $USER;

		$arMenuApps = [];
		foreach ((new Public\Provider\MenuApplicationProvider())->getForMobile((int)$USER->GetID()) as $app)
		{
			$arMenuApps[] = [
				'id' => $app->id,
				'title' => $app->menuName,
				'imageName' => 'market',
				'params' => [
					'url' => SITE_DIR . 'mobile/marketplace/?id=' . $app->id,
				],
			];
		}

		return $arMenuApps;
	}
}

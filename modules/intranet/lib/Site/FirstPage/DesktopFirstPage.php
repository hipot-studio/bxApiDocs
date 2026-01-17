<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Site\FirstPage;

use Bitrix\Intranet\Integration\Im\Desktop;
use Bitrix\Intranet\UI\LeftMenu\Menu;
use Bitrix\Main\Web\Uri;

class DesktopFirstPage extends IntranetFirstPage
{
	private const DEFAULT_PATH = 'stream/';

	public function isEnabled(): bool
	{
		return false;
		//return Desktop::getInstance()->isDesktopRequest();
	}

	public function getUri(): Uri
	{
		$uri = parent::getUri();
		if ($this->isChatUri($uri))
		{
			return $this->getFirstNonChatMenuUri();
		}

		return $uri;
	}

	private function isChatUri(Uri $uri): bool
	{
		return (bool)preg_match('~^' . SITE_DIR . 'online/~i', $uri->getPathQuery());
	}

	private function getFirstNonChatMenuUri(): Uri
	{
		$items = $this->getMenuItems();
		foreach ($items as $item)
		{
			$path = $this->getPathByMenuItem($item);
			$uri = new Uri($path);
			if (!$this->isChatUri($uri) && $this->isValidUri($uri))
			{
				return $uri;
			}
		}

		return new Uri(SITE_DIR . self::DEFAULT_PATH);
	}

	private function getMenuItems(): array
	{
		$menu = Menu::getDefaultForCurrentUser();

		return array_merge($menu->getVisibleItems(), $menu->getHiddenItems());
	}

	private function getPathByMenuItem(array $menuItem): string
	{
		if (isset($menuItem['PARAMS']['real_link']) && is_string($menuItem['PARAMS']['real_link']))
		{
			$path = $menuItem['PARAMS']['real_link'];
		}
		else
		{
			$path = isset($menuItem['LINK']) && is_string($menuItem['LINK']) ? $menuItem['LINK'] : '';
		}

		if (preg_match('~^' . SITE_DIR . 'index\\.php~i', $path))
		{
			$path = SITE_DIR;
		}
		elseif (!empty($menuItem['PARAMS']['onclick']))
		{
			$path = '';
		}

		return $path;
	}

	private function isValidUri(Uri $uri): bool
	{
		$rootPaths = ['', SITE_DIR, SITE_DIR . 'index.php'];

		return !in_array($uri->getPath(), $rootPaths, true);
	}
}

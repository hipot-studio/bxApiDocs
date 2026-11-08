<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\LeftMenu;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Main\Data\Cache;

class LeftMenuCacheInvalidator
{
	private ?\CBitrixComponent $menuComponent = null;

	public function invalidateUser(int $userId): void
	{
		$firstException = null;

		try
		{
			$this->deleteMenuComponentCache($userId);
		}
		catch (\Throwable $exception)
		{
			$firstException = $exception;
		}

		try
		{
			$this->deleteCompositeCache($userId);
		}
		catch (\Throwable $exception)
		{
			$firstException ??= $exception;
		}

		if ($firstException !== null)
		{
			throw $firstException;
		}
	}

	protected function deleteMenuComponentCache(int $userId): void
	{
		Cache::createInstance()->cleanDir($this->getMenuComponentCachePath($userId));
	}

	protected function deleteCompositeCache(int $userId): void
	{
		CacheProvider::deleteUserCache($userId);
	}

	protected function getMenuComponentCachePath(int $userId): string
	{
		return $this->getMenuComponent()->getGenerationCachePath($userId);
	}

	protected function getMenuComponent(): \CBitrixComponent
	{
		$this->menuComponent ??= $this->createMenuComponent();

		return $this->menuComponent;
	}

	protected function createMenuComponent(): \CBitrixComponent
	{
		$baseComponent = new \CBitrixComponent();
		if (!$baseComponent->initComponent('bitrix:menu'))
		{
			throw new \RuntimeException('The menu component is unavailable.');
		}

		$componentClass = \CBitrixComponent::includeComponentClass('bitrix:menu');
		if ($componentClass === '' || !is_subclass_of($componentClass, \CBitrixComponent::class))
		{
			throw new \RuntimeException('The menu component class is unavailable.');
		}

		return new $componentClass($baseComponent);
	}
}

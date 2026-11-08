<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Main\ArgumentException;

final class SettingsHubNavigationRegistry
{
	/** @var array<string, \Closure(): SettingsHubNavigationItem> */
	private array $factoriesById = [];

	/** @var array<string, SettingsHubNavigationItem> */
	private array $itemsById = [];

	public function __construct(SettingsHubNavigationFactory $factory)
	{
		$factory->register($this);
	}

	/** @param \Closure(): SettingsHubNavigationItem $factory */
	public function register(string $id, \Closure $factory): void
	{
		if (preg_match('/^[a-z0-9-]+$/D', $id) !== 1)
		{
			throw new ArgumentException('Navigation item id is invalid.', 'id');
		}

		if (isset($this->factoriesById[$id]))
		{
			throw new ArgumentException('Navigation item id must be unique.', 'id');
		}

		$this->factoriesById[$id] = $factory;
	}

	public function get(string $id): ?SettingsHubNavigationItem
	{
		if (!isset($this->factoriesById[$id]))
		{
			return null;
		}

		if (!isset($this->itemsById[$id]))
		{
			$item = ($this->factoriesById[$id])();
			$this->validateItem($item);
			if ($item->id() !== $id)
			{
				throw new ArgumentException('Navigation item id must match its registration.', 'factory');
			}

			$this->itemsById[$id] = $item;
		}

		return $this->itemsById[$id];
	}

	/** @return SettingsHubNavigationItem[] */
	public function getAll(): array
	{
		return array_map($this->get(...), array_keys($this->factoriesById));
	}

	private function validateItem(SettingsHubNavigationItem $item): void
	{
		$isUrl = $item instanceof SettingsHubUrlNavigationItem;
		$isWrapped = $item instanceof SettingsHubWrappedNavigationItem;
		if ($isUrl === $isWrapped)
		{
			throw new ArgumentException(
				'Navigation item must implement exactly one specialized navigation item interface.',
				'items',
			);
		}
	}
}

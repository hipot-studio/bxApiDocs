<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\EntitySelector;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class SelectorProvider
{
	/**
	 * The registry as the event answered it, kept for the lifetime of the provider. Every constant item of a
	 * wizard form asks whether its selector exists, and the provider is a service-locator singleton, so without
	 * this the event would be dispatched once per item.
	 *
	 * @var SelectorConfiguration[]|null
	 */
	private ?array $selectors = null;

	/**
	 * @return SelectorConfiguration[]
	 */
	public function getAll(): array
	{
		return $this->selectors ??= $this->collectSelectors();
	}

	/**
	 * @return SelectorConfiguration[]
	 */
	private function collectSelectors(): array
	{
		$selectors = [];

		$event = new Event('bizproc', 'SetupTemplateActivitySelectorProvider::onProvideSelectors', []);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				continue;
			}

			$eventSelectors = $eventResult->getParameters()['selectors'] ?? [];
			if (!is_array($eventSelectors))
			{
				continue;
			}

			foreach ($eventSelectors as $eventSelector)
			{
				if (!$eventSelector instanceof SelectorConfiguration)
				{
					continue;
				}

				$selectors[] = $eventSelector;
			}
		}

		return $selectors;
	}

	public function isExists(mixed $id): bool
	{
		return $this->getById($id) !== null;
	}

	public function getById(mixed $id): ?SelectorConfiguration
	{
		return $this->first(static fn (SelectorConfiguration $selector) => $selector->id === $id);
	}

	public function first(?callable $filter = null): ?SelectorConfiguration
	{
		foreach ($this->getAll() as $selector)
		{
			if ($filter === null || $filter($selector))
			{
				return $selector;
			}
		}

		return null;
	}
}

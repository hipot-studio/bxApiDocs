<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\EntitySelector;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class SelectorProvider
{
	/**
	 * @return SelectorConfiguration[]
	 */
	public function getAll(): array
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

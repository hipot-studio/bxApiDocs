<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MailTemplate;

use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplatePage;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use InvalidArgumentException;
use Throwable;

final class TemplateCatalog
{
	public const RESULT_RECORDED = 'recorded';

	private const QUICK_LIST_LIMIT = 5;
	private const SEARCH_LIMIT_MAX = 50;

	/** @var array<string, TemplateProvider> */
	private array $providers;

	/**
	 * Providers are keyed by the stable source code used by TemplateReference.
	 *
	 * @param array<string, TemplateProvider> $providers
	 */
	public function __construct(
		array $providers,
		private readonly TemplateUsageHistory $history,
	)
	{
		foreach ($providers as $source => $provider)
		{
			if (!is_string($source) || trim($source) === '' || !$provider instanceof TemplateProvider)
			{
				throw new InvalidArgumentException('Template providers must be keyed by a non-empty source.');
			}
		}

		$this->providers = $providers;
	}

	/**
	 * A caller that has just written a template but was not told which one, a CRM that answers the panel with
	 * no template of its own, asks for the newest of the provider to lead the list: a history of the user
	 * would otherwise hide the template just written behind the ones applied before it.
	 *
	 * @return list<TemplateListItem>
	 */
	public function quickList(
		int $userId,
		?TemplateReference $priorityReference = null,
		bool $pinRecentlyCreated = false,
	): array
	{
		$historyReferences = $this->history->getReferences($userId);
		$resolvedItems = $this->resolveHistory($historyReferences, $userId);
		$priorityItem = $priorityReference !== null
			? $this->resolvePriority($priorityReference, $userId)
			: null
		;
		$needsNewest = $priorityItem === null && $pinRecentlyCreated;
		$recentlyCreated = empty($resolvedItems) || $needsNewest
			? $this->recentlyCreated($userId)
			: []
		;
		$items = !empty($resolvedItems)
			? array_slice($resolvedItems, 0, self::QUICK_LIST_LIMIT)
			: $recentlyCreated
		;
		if ($needsNewest)
		{
			$priorityItem = $recentlyCreated[0] ?? null;
		}

		if ($priorityItem === null)
		{
			return array_slice($items, 0, self::QUICK_LIST_LIMIT);
		}

		$priorityKey = $this->referenceKey($priorityItem->getReference());
		$items = array_values(array_filter(
			$items,
			fn(TemplateListItem $item): bool => $this->referenceKey($item->getReference()) !== $priorityKey,
		));

		return array_slice([$priorityItem, ...$items], 0, self::QUICK_LIST_LIMIT);
	}

	public function search(string $query, int $userId, int $offset = 0, int $limit = 20): TemplatePage
	{
		$offset = max(0, $offset);
		$limit = max(1, min(self::SEARCH_LIMIT_MAX, $limit));

		foreach ($this->providers as $provider)
		{
			if (!$this->isProviderAvailable($provider, $userId))
			{
				continue;
			}

			try
			{
				$page = $provider->search($query, $userId, $offset, $limit);
				if (!empty($page->getItems()) || $page->hasMore())
				{
					return $page;
				}
			}
			catch (Throwable)
			{
				continue;
			}
		}

		return new TemplatePage([], null, false);
	}

	public function prepare(TemplateReference $reference, int $userId): Result
	{
		$provider = $this->providers[$reference->getSource()] ?? null;
		if ($provider === null)
		{
			return $this->createErrorResult(
				'Template is not available.',
				TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE,
			);
		}

		if (!$this->isProviderAvailable($provider, $userId))
		{
			return $this->createErrorResult(
				'Template provider is unavailable.',
				TemplateProvider::ERROR_PROVIDER_UNAVAILABLE,
			);
		}

		try
		{
			return $provider->prepare($reference, $userId);
		}
		catch (Throwable)
		{
			return $this->createErrorResult(
				'Template preparation failed.',
				TemplateProvider::ERROR_PREPARATION_FAILED,
			);
		}
	}

	/**
	 * The only place history is written, so the entries the provider no longer applies are dropped here as
	 * well: reading a list must leave the stored history alone. The rest of the history rides along in the
	 * revalidation query and costs no extra call.
	 */
	public function recordUsage(TemplateReference $reference, int $userId): Result
	{
		$provider = $this->providers[$reference->getSource()] ?? null;
		if ($provider === null)
		{
			return $this->createErrorResult(
				'Template is not available.',
				TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE,
			);
		}

		if (!$this->isProviderAvailable($provider, $userId))
		{
			return $this->createErrorResult(
				'Template provider is unavailable.',
				TemplateProvider::ERROR_PROVIDER_UNAVAILABLE,
			);
		}

		$historyReferences = $this->historyReferencesOfSource($reference, $userId);

		try
		{
			$items = $provider->getByIds([$reference, ...$historyReferences], $userId);
		}
		catch (Throwable)
		{
			return $this->createErrorResult(
				'Template provider is unavailable.',
				TemplateProvider::ERROR_PROVIDER_UNAVAILABLE,
			);
		}

		$applicableByKey = [];
		foreach ($items as $item)
		{
			if ($item instanceof TemplateListItem && $item->canApply())
			{
				$applicableByKey[$this->referenceKey($item->getReference())] = $item;
			}
		}

		if (!isset($applicableByKey[$this->referenceKey($reference)]))
		{
			return $this->createErrorResult(
				'Template is not available.',
				TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE,
			);
		}

		$this->history->recordUsage($userId, $reference);
		$this->removeUnavailableFromHistory($historyReferences, $applicableByKey, $userId);

		return (new Result())->setData([self::RESULT_RECORDED => true]);
	}

	/**
	 * @param list<TemplateReference> $references
	 * @return list<TemplateListItem>
	 */
	private function resolveHistory(array $references, int $userId): array
	{
		$referencesBySource = [];
		foreach ($references as $reference)
		{
			$referencesBySource[$reference->getSource()][] = $reference;
		}

		$resolvedByKey = [];
		foreach ($referencesBySource as $source => $sourceReferences)
		{
			$provider = $this->providers[$source] ?? null;
			if ($provider === null || !$this->isProviderAvailable($provider, $userId))
			{
				continue;
			}

			try
			{
				$items = $provider->getByIds($sourceReferences, $userId);
			}
			catch (Throwable)
			{
				continue;
			}

			foreach ($items as $item)
			{
				if ($item->canApply())
				{
					$resolvedByKey[$this->referenceKey($item->getReference())] = $item;
				}
			}
		}

		$resolved = [];
		foreach ($references as $reference)
		{
			$item = $resolvedByKey[$this->referenceKey($reference)] ?? null;
			if ($item !== null)
			{
				$resolved[] = $item;
			}
		}

		return $resolved;
	}

	/**
	 * @return list<TemplateListItem>
	 */
	private function recentlyCreated(int $userId): array
	{
		foreach ($this->providers as $provider)
		{
			if (!$this->isProviderAvailable($provider, $userId))
			{
				continue;
			}

			try
			{
				$items = array_values(array_filter(
					$provider->getRecentlyCreated($userId, self::QUICK_LIST_LIMIT),
					static fn(mixed $item): bool => $item instanceof TemplateListItem && $item->canApply(),
				));
				if (!empty($items))
				{
					return array_slice($items, 0, self::QUICK_LIST_LIMIT);
				}
			}
			catch (Throwable)
			{
				continue;
			}
		}

		return [];
	}

	private function resolvePriority(TemplateReference $reference, int $userId): ?TemplateListItem
	{
		$provider = $this->providers[$reference->getSource()] ?? null;
		if ($provider === null || !$this->isProviderAvailable($provider, $userId))
		{
			return null;
		}

		try
		{
			$items = $provider->getByIds([$reference], $userId);
		}
		catch (Throwable)
		{
			return null;
		}

		$item = $items[0] ?? null;

		return $item instanceof TemplateListItem
			&& $item->canApply()
			&& $item->getReference()->equals($reference)
				? $item
				: null
		;
	}

	/**
	 * A reference of another source answers to another provider, and the one being recorded is asked about
	 * on its own, so neither of them joins the history part of the query.
	 *
	 * @return list<TemplateReference>
	 */
	private function historyReferencesOfSource(TemplateReference $reference, int $userId): array
	{
		return array_values(array_filter(
			$this->history->getReferences($userId),
			static fn(TemplateReference $historyReference): bool
				=> $historyReference->getSource() === $reference->getSource()
					&& !$historyReference->equals($reference),
		));
	}

	/**
	 * @param list<TemplateReference> $references
	 * @param array<string, TemplateListItem> $applicableByKey
	 */
	private function removeUnavailableFromHistory(array $references, array $applicableByKey, int $userId): void
	{
		$unavailable = array_values(array_filter(
			$references,
			fn(TemplateReference $reference): bool => !isset($applicableByKey[$this->referenceKey($reference)]),
		));
		if (empty($unavailable))
		{
			return;
		}

		$this->history->removeUnavailable($userId, $unavailable);
	}

	private function referenceKey(TemplateReference $reference): string
	{
		return $reference->getSource() . "\0" . $reference->getId();
	}

	private function isProviderAvailable(TemplateProvider $provider, int $userId): bool
	{
		try
		{
			return $provider->isAvailable($userId);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	private function createErrorResult(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}

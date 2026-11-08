<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use CCrmOwnerType;
use Psr\Log\LoggerInterface;
use Throwable;

final class AiScreeningDataCollectorManager
{
	private ?LoggerInterface $logger = null;
	private const HISTORY_COLLECTION_LIMIT = 5;

	public function __construct(
		private readonly AiScreeningDataCollectorConfig $dataCollectorConfig,
		private ?EntityDataCollector $itemsCollector = null,
	)
	{
		$this->logger = $this->dataCollectorConfig->getLogger();
	}

	public function collectCopilotData(): array
	{
		// Screening is a system background process: candidates are selected without
		// per-user permission checks (see DealsProvider), so data collection must run
		// with system-level access too and must not depend on the launching user's rights.
		$targetItemIdentifier = $this->dataCollectorConfig->getTargetItemIdentifier();

		try
		{
			$result = $this->collectDealsContext($targetItemIdentifier->getEntityId());

			$this->applyTranscriptBudget($result);

			return $result;
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data for deal {target}: {exception}' . PHP_EOL,
				[
					'target' => $targetItemIdentifier,
					'exception' => $exception,
				],
			);

			return [];
		}
	}

	/**
	 * Collects the target deal apart from the client's history: the history selection is limited to
	 * HISTORY_COLLECTION_LIMIT items, so the target deal is missing from it as soon as the client has
	 * that many newer deals. Only then it is fetched by id - the selection itself is expensive.
	 *
	 * @return array<string, mixed>
	 */
	private function collectDealsContext(int $targetItemId): array
	{
		$clientIdentifiers = $this->dataCollectorConfig->getClientIdentifiers();

		[$itemsList, $ordersSummary] = $this->getItemsCollector()
			->setLimit(self::HISTORY_COLLECTION_LIMIT)
			->getMarkers([
				'clientIdentifiers' => $clientIdentifiers,
				'baseEntityId' => $targetItemId,
			])
		;

		$baseDealInfo = $itemsList[$targetItemId] ?? [];
		unset($itemsList[$targetItemId]);
		$dealList = array_values($itemsList);

		// the fetch by id carries no client predicate, so with an unknown client it would put a deal
		// into the payload apart from the history it is judged against
		if (empty($baseDealInfo) && !empty($clientIdentifiers))
		{
			$baseDealInfo = $this->getItemsCollector()->getMarkersForEntity($targetItemId);
		}

		return [
			'base_deal_info' => $baseDealInfo,
			'deals_list' => $dealList,
			'orders_summary' => $ordersSummary ?? [],
		];
	}

	/**
	 * Applies the aggregate transcript budget (layer A) across the client's communication_data,
	 * base deal first, then deals_list by freshness. No-op when the feature or budget is off.
	 *
	 * @param array<string, mixed> $result
	 */
	private function applyTranscriptBudget(array &$result): void
	{
		$commBlocks = [];

		if (isset($result['base_deal_info']['communication_data']) && is_array($result['base_deal_info']['communication_data']))
		{
			$commBlocks[] = &$result['base_deal_info']['communication_data'];
		}

		if (isset($result['deals_list']) && is_array($result['deals_list']))
		{
			foreach ($result['deals_list'] as &$deal)
			{
				if (isset($deal['communication_data']) && is_array($deal['communication_data']))
				{
					$commBlocks[] = &$deal['communication_data'];
				}
			}
			unset($deal);
		}

		(new TranscriptBudgetTrimmer())->trimResult($commBlocks);
	}

	private function getItemsCollector(): EntityDataCollector
	{
		if ($this->itemsCollector === null)
		{
			$this->itemsCollector = new EntityDataCollector(CCrmOwnerType::Deal);
		}

		return $this->itemsCollector;
	}
}

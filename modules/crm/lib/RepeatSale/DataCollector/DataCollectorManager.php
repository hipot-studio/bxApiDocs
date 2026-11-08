<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Psr\Log\LoggerInterface;
use Throwable;

final class DataCollectorManager
{
	private UserPermissions $userPermissions;
	private ?ClientDataCollector $clientCollector = null;
	private ?EntityDataCollector $dealCollector = null;
	private ?ItemIdentifier $baseDealIdentifier = null;

	public function __construct(
		private readonly ItemIdentifier $entityIdentifier,
		private readonly ItemIdentifier $clientIdentifier,
		private readonly LoggerInterface $logger,
		private readonly ?int $userId = null,
	)
	{
		$this->userPermissions = Container::getInstance()->getUserPermissions($this->userId);
	}

	public function setBaseDealIdentifier(?ItemIdentifier $baseDealIdentifier): self
	{
		$this->baseDealIdentifier = $baseDealIdentifier;

		return $this;
	}

	public function collectCopilotData(): array
	{
		$isClientPermitted = $this->userPermissions->item()->canRead(
			$this->clientIdentifier->getEntityTypeId(),
			$this->clientIdentifier->getEntityId(),
		);
		if (!$isClientPermitted)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data for client {target}: access denied' . PHP_EOL,
				[
					'target' => $this->clientIdentifier,
				],
			);

			return [];
		}

		$isEntityPermitted = $this->userPermissions->item()->canRead(
			$this->entityIdentifier->getEntityTypeId(),
			$this->entityIdentifier->getEntityId(),
		);

		if (!$isEntityPermitted)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data for client {target}: access denied to {entity}' . PHP_EOL,
				[
					'target' => $this->clientIdentifier,
					'entity' => $this->entityIdentifier,
				],
			);

			return [];
		}

		try
		{
			$clientInfo = $this->getClientCollector()->getMarkers([
				'entityId' => $this->clientIdentifier->getEntityId(),
			]);

			if (empty($clientInfo))
			{
				return [];
			}

			$baseDeal = $this->baseDealIdentifier !== null
				? $this->fetchBaseDeal($this->baseDealIdentifier)
				: []
			;

			$dealCollectorParams = [
				'entityId' => $this->entityIdentifier->getEntityId(),
				'clientIdentifiers' => [
					$this->clientIdentifier->jsonSerialize(),
				],
			];
			if ($this->baseDealIdentifier !== null)
			{
				$dealCollectorParams['excludeId'] = $this->baseDealIdentifier->getEntityId();

				if (!empty($baseDeal))
				{
					// the base deal goes to the AI apart from the history, but the purchases aggregate
					// counts it - and only the one this user was allowed to read
					$dealCollectorParams['baseEntityId'] = $this->baseDealIdentifier->getEntityId();
				}
			}

			[$dealsList, $ordersSummary] = $this->getDealCollector()->getMarkers($dealCollectorParams);
			$dealsList ??= [];

			$dealsListForChannel = empty($baseDeal) ? $dealsList : [...$dealsList, $baseDeal];

			$result = [
				'client_info' => $clientInfo,
				'deals_list' => $dealsList,
				'orders_summary' => $ordersSummary ?? [],
				'preferred_communication_channel' => $this->getPreferredCommunicationChannel($dealsListForChannel),
			];

			if (!empty($baseDeal))
			{
				$result['base_deal'] = $baseDeal;
			}

			$this->applyTranscriptBudget($result);

			return $result;
		}
		catch (Throwable $exception)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data for client {target}: {exception}' . PHP_EOL,
				[
					'target' => $this->clientIdentifier,
					'exception' => $exception,
				],
			);

			return [];
		}
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

		if (isset($result['base_deal']['communication_data']) && is_array($result['base_deal']['communication_data']))
		{
			$commBlocks[] = &$result['base_deal']['communication_data'];
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

	private function getClientCollector(): ClientDataCollector
	{
		if ($this->clientCollector === null)
		{
			$this->clientCollector = new ClientDataCollector($this->clientIdentifier->getEntityTypeId());
		}

		return $this->clientCollector;
	}

	private function getDealCollector(): EntityDataCollector
	{
		if ($this->dealCollector === null)
		{
			$this->dealCollector = new EntityDataCollector($this->entityIdentifier->getEntityTypeId());
		}

		return $this->dealCollector;
	}

	private function fetchBaseDeal(ItemIdentifier $baseDealIdentifier): array
	{
		$isPermitted = $this->userPermissions->item()->canRead(
			$baseDealIdentifier->getEntityTypeId(),
			$baseDealIdentifier->getEntityId(),
		);
		if (!$isPermitted)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data for client {target}: access denied to base deal {baseDeal}' . PHP_EOL,
				[
					'target' => $this->clientIdentifier,
					'baseDeal' => $baseDealIdentifier,
				],
			);

			return [];
		}

		return $this->getDealCollector()->getMarkersForEntity($baseDealIdentifier->getEntityId());
	}

	private function getPreferredCommunicationChannel(array $dealsList): string
	{
		$counts = array_reduce(
			array_filter(array_column($dealsList, 'communication_data')),
			static function($counts, $arr) {
				foreach ($arr as $type => $items)
				{
					if (ActivityType::isCommunicationChannel($type))
					{
						$counts[$type] = ($counts[$type] ?? 0) + count($items);
					}
				}

				return $counts;
			},
			[],
		);

		$channel = empty($counts)
			? ''
			: array_keys($counts, max($counts))[0] ?? '';

		return ActivityType::mapCommunicationChannel($channel);
	}
}

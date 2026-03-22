<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;
use Throwable;

final class AiScreeningDataCollectorManager
{
	private UserPermissions $userPermissions;
	private ?EntityDataCollector $itemsCollector = null;
	private ?LoggerInterface $logger = null;
	private const ITEMS_COLLECTION_LIMIT = 6;

	public function __construct(
		private readonly AiScreeningDataCollectorConfig $dataCollectorConfig,
	)
	{
		$userId = $this->dataCollectorConfig->getUserId();
		$this->userPermissions = Container::getInstance()->getUserPermissions($userId);

		$this->logger = $this->dataCollectorConfig->getLogger();
	}

	public function collectCopilotData(): array
	{
		$targetItemIdentifier = $this->dataCollectorConfig->getTargetItemIdentifier();
		$isItemPermitted = $this->userPermissions->item()->canReadItemIdentifier($targetItemIdentifier);

		if (!$isItemPermitted)
		{
			$this->logger->error(
				'{date}: Failed to collect copilot data: access denied to {entity}' . PHP_EOL,
				[
					'entity' => $targetItemIdentifier,
				],
			);

			return [];
		}

		try
		{
			$clientIdentifiers = $this->dataCollectorConfig->getClientIdentifiers();

			[$itemsList, $ordersSummary] = $this->getItemsCollector()
				->setLimit(self::ITEMS_COLLECTION_LIMIT)
				->getMarkers([
					'clientIdentifiers' => $clientIdentifiers,
				])
			;

			$baseDealInfo = [];
			$dealList = [];
			$targetItemId = $targetItemIdentifier->getEntityId();
			foreach ($itemsList as $id => $item)
			{
				if ($id === $targetItemId)
				{
					$baseDealInfo = $item;
				}
				else
				{
					$dealList[] = $item;
				}
			}

			return [
				'base_deal_info' => $baseDealInfo,
				'deals_list' => $dealList,
				'orders_summary' => $ordersSummary ?? [],
			];
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

	private function getItemsCollector(): EntityDataCollector
	{
		if ($this->itemsCollector === null)
		{
			$this->itemsCollector = new EntityDataCollector(CCrmOwnerType::Deal);
		}

		return $this->itemsCollector;
	}
}

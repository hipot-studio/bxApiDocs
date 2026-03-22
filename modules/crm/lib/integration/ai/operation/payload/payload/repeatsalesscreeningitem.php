<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\AiScreeningDataCollectorConfig;
use Bitrix\Crm\RepeatSale\DataCollector\AiScreeningDataCollectorManager;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class RepeatSalesScreeningItem extends AbstractPayload implements CalcMarkersInterface
{
	public function getPayloadCode(): string
	{
		return 'repeat_sales_screening_item';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$crmData = $this->getCrmData();
		if (empty($crmData))
		{
			return [];
		}

		return [
			'crm_data' => in_array('crm_data', $this->encodedMarkers, true)
				? Json::encode($crmData)
				: $crmData,
		];
	}

	private function getCrmData(): array
	{
		if (!$this->identifier || $this->identifier->getEntityTypeId() !== CCrmOwnerType::Deal)
		{
			return [];
		}

		$entityIdentifier = new ItemIdentifier($this->identifier->getEntityTypeId(), $this->identifier->getEntityId());

		$dataCollectorConfig = new AiScreeningDataCollectorConfig(
			$entityIdentifier,
			$this->additionalData['clientIdentifiers'] ?? [],
			$this->userId,
			AIManager::logger(),
		);

		return (new AiScreeningDataCollectorManager($dataCollectorConfig))->collectCopilotData();
	}
}

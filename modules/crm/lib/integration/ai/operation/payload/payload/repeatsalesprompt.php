<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\SandboxInterface;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Crm\RepeatSale\DataCollector\DataCollectorManager;
use Bitrix\Crm\RepeatSale\Sandbox\SandboxManager;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class RepeatSalesPrompt extends AbstractPayload implements CalcMarkersInterface, SandboxInterface
{
	private array $dataForCalcMarkers = [];

	public function getPayloadCode(): string
	{
		if ($this->getBaseDealId() === null)
		{
			return 'repeat_sales_prompt';
		}

		return 'repeat_sales_with_base_deal_prompt';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		if ($this->isUseDataFromSandbox())
		{
			$segmentId = (int)($this->dataForCalcMarkers['segmentId'] ?? 0);
			$clientEntityTypeId = (int)($this->dataForCalcMarkers['clientEntityTypeId'] ?? 0);
			$clientEntityId = (int)($this->dataForCalcMarkers['clientEntityId'] ?? 0);
			$entityIdentifier = $this->identifier;
			$baseDealId = $this->getBaseDealId();
		}
		else
		{
			$activity = $this->getActivity();
			if (empty($activity))
			{
				return []; // no activity
			}

			$providerParams = $activity['PROVIDER_PARAMS'] ?? [];
			$segmentId = (int)($activity['PROVIDER_PARAMS']['SEGMENT_ID'] ?? 0);
			$clientEntityTypeId = (int)($providerParams['CLIENT_ENTITY_TYPE_ID'] ?? $providerParams['BASE_ENTITY_TYPE_ID'] ?? 0);
			$clientEntityId = (int)($providerParams['CLIENT_ENTITY_ID'] ?? $providerParams['BASE_ENTITY_ID'] ?? 0);
			$entityIdentifier = (new TargetResolver())->findTarget($this->identifier->getEntityId());
			$baseDealId = $this->getBaseDealId();
		}

		$segmentData = $this->getSegmentData($segmentId);
		$crmData = $this->getCrmData($clientEntityTypeId, $clientEntityId, $entityIdentifier, $baseDealId);
		if (empty($segmentData) || empty($crmData))
		{
			return []; // no data
		}

		$segmentData = in_array('segment_data', $this->encodedMarkers, true)
			? Json::encode(['client_segment' => $segmentData])
			: ['client_segment' => $segmentData];
		$crmDataSufficient = $this->isCrmDataSufficient($crmData);
		$crmData = in_array('crm_data', $this->encodedMarkers, true)
			? Json::encode($crmData)
			: $crmData;

		return [
			'segment_data' => $segmentData,
			'crm_data' => $crmData,
			'crm_data_sufficient' => $crmDataSufficient,
		];
	}

	private function isUseDataFromSandbox(): bool
	{
		return !empty($this->dataForCalcMarkers) && SandboxManager::getInstance()->isAvailableSandboxMode();
	}

	private function getBaseDealId(): ?int
	{
		if ($this->isUseDataFromSandbox())
		{
			$baseDealId = (int)($this->dataForCalcMarkers['baseDealId'] ?? 0);

			return $baseDealId > 0 ? $baseDealId : null;
		}

		$activity = $this->getActivity();
		$providerParams = $activity['PROVIDER_PARAMS'] ?? [];
		$baseEntityTypeId = (int)($providerParams['BASE_ENTITY_TYPE_ID'] ?? 0);
		$baseEntityId = (int)($providerParams['BASE_ENTITY_ID'] ?? 0);

		return $baseEntityTypeId === CCrmOwnerType::Deal && $baseEntityId > 0
			? $baseEntityId
			: null
		;
	}

	private function getSegmentData(int $segmentId): array
	{
		if ($segmentId <= 0)
		{
			return [];
		}

		return RepeatSaleSegmentController::getInstance()->collectCopilotData($segmentId);
	}

	private function getCrmData(
		int $clientEntityTypeId,
		int $clientEntityId,
		?ItemIdentifier $entityIdentifier,
		?int $baseDealId = null,
	): array
	{
		if ($clientEntityTypeId <= 0 || $clientEntityId <= 0)
		{
			return []; // no client
		}

		if ($entityIdentifier?->getEntityTypeId() !== CCrmOwnerType::Deal)
		{
			return []; // currently only deal supported
		}

		$manager = new DataCollectorManager(
			$entityIdentifier,
			new ItemIdentifier($clientEntityTypeId, $clientEntityId),
			AIManager::logger(),
			$this->userId,
		);

		if ($baseDealId !== null)
		{
			$manager->setBaseDealIdentifier(new ItemIdentifier(CCrmOwnerType::Deal, $baseDealId));
		}

		return $manager->collectCopilotData();
	}

	private function isCrmDataSufficient(array $data): bool
	{
		$dealList = $data['deals_list'] ?? [];
		if (empty($dealList))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getCommunicationFieldsLimit();
		$callback = static fn(array $item): bool => TextHelper::countCharactersInArrayFlexible($item['communication_data'] ?? []) > $limit;

		return !empty(array_filter($dealList, $callback));
	}

	public function setSandboxData(array $data): self
	{
		$this->dataForCalcMarkers = $data;

		return $this;
	}
}

<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\ScreeningRepeatSaleItemPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\ScreeningRepeatSaleItemEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Crm\RepeatSale\Schedule\Scheduler;
use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;
use Bitrix\Main;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class ScreeningRepeatSaleItem extends AbstractOperation
{
	public const TYPE_ID = 7;
	public const CONTEXT_ID = 'screening_repeat_sale_item';

	protected const PAYLOAD_CLASS = ScreeningRepeatSaleItemPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_REPEAT_SALE_ENGINE_CODE;

	private ?int $segmentId = null;

	/* @var ItemIdentifier[] $clientIdentifiers */
	private array $clientIdentifiers = [];

	public function setSegmentId(int $segmentId): self
	{
		$this->segmentId = $segmentId;

		return $this;
	}

	public function setClientIdentifiers(array $clientIdentifiers): self
	{
		$this->clientIdentifiers = $clientIdentifiers;

		return $this;
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	protected function getAIPayload(): Main\Result
	{
		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setEncodedMarkers(['segment_data', 'crm_data'])
			->setAdditionalData([
				'segmentId' => $this->segmentId,
				'clientIdentifiers' => $this->clientIdentifiers,
			])
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError();

			return (new Main\Result())->addError($error);
		}

		return $result;
	}

	private function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		$crmData = Json::decode($markers['crm_data'] ?? '');
		if (empty($crmData))
		{
			return false;
		}

		$baseDealInfo = $crmData['base_deal_info'] ?? [];
		if (empty($baseDealInfo))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getMinSufficientAiCollectorDealFieldsLength();
		$dealFields = $baseDealInfo['deal_fields'] ?? [];
		$communicationData = $baseDealInfo['communication_data'] ?? [];

		return
			TextHelper::countCharactersInArrayFlexible($dealFields, true) > $limit
			|| TextHelper::countCharactersInArrayFlexible($communicationData) > $limit;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new ScreeningRepeatSaleItemPayload([]);
		}

		return new ScreeningRepeatSaleItemPayload([
			'category' => $json['category'] ?? null,
			'isRepeatSalePossible' => $json['isRepeatSalePossible'] ?? 0,
		]);
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var ScreeningRepeatSaleItemPayload $payload */
		$payload = $result->getPayload();

		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in screening entity item of repeat sale: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$ownerId = $result->getTarget()?->getEntityId();
		$ownerTypeId = $result->getTarget()?->getEntityTypeId();
		$segmentId = $context?->getParameters()['additionalInfo']['segmentId'] ?? null;

		$screeningItem = RepeatSaleAiScreeningTable::query()
			->setSelect(['ID'])
			->setFilter([
				'=OWNER_TYPE_ID' => $ownerTypeId,
				'=OWNER_ID' => $ownerId,
				'=SEGMENT_ID' => $segmentId,
			])
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;

		if (!$screeningItem)
		{
			AIManager::logger()->error(
				'{date}: {class}: Screening item not found: {target} {segmentId}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
					'segmentId' => $segmentId,
				],
			);

			return;
		}

		if ($payload->isRepeatSalePossible === true)
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSalePossible->value);
		}
		else
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSaleNotPossible->value);
		}

		$screeningItem->setCategory($payload->category);
		$screeningItem->save();

		$waitingItemsCount = RepeatSaleAiScreeningTable::query()
			->where('DESIRED_CREATION_DATE', new Main\Type\Date())
			->whereNull('AI_OPINION')
			->queryCountTotal()
		;

		if ((int)$waitingItemsCount === 0)
		{
			Scheduler::getInstance()->addChildrenJobsToQueueIfNotExists($segmentId);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Error on: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);
	}

	protected static function getJobFinishEventBuilder(): ScreeningRepeatSaleItemEvent
	{
		return new ScreeningRepeatSaleItemEvent();
	}

	protected function getContextAdditionalInfo(): array
	{
		$additionalInfo = parent::getContextAdditionalInfo();

		$additionalInfo['segmentId'] = $this->segmentId;

		return $additionalInfo;
	}
}

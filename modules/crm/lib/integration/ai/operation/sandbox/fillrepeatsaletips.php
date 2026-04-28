<?php

namespace Bitrix\Crm\Integration\AI\Operation\Sandbox;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\FillRepeatSaleTipsPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\AbstractFillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\FillRepeatSaleTipsEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Sandbox\SandboxManager;
use CCrmOwnerType;

final class FillRepeatSaleTips extends AbstractFillRepeatSaleTips
{
	public const TYPE_ID = 8;

	private ?ItemIdentifier $clientIdentifier = null;
	private ?int $segmentId = null;

	public function setClientIdentifier(?ItemIdentifier $clientIdentifier): self
	{
		$this->clientIdentifier = $clientIdentifier;

		return $this;
	}

	public function setSegmentId(?int $segmentId): self
	{
		$this->segmentId = $segmentId;

		return $this;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	protected function getAIPayload(): \Bitrix\Main\Result
	{
		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setSandboxData([
				'segmentId' => $this?->segmentId,
				'clientEntityTypeId' => $this->clientIdentifier?->getEntityTypeId(),
				'clientEntityId' => $this->clientIdentifier?->getEntityId(),
			])
			->setEncodedMarkers(['segment_data', 'crm_data'])
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError();

			return (new \Bitrix\Main\Result())->addError($error);
		}

		return $result;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		if ($result->isSuccess())
		{
			$result = SandboxManager::getInstance()->add(
				$result->getJobId(),
				$result->getTarget(),
			);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// not implemented yet
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var FillRepeatSaleTipsPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Job error: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$updateResult = SandboxManager::getInstance()->appendPayloadData($result->getJobId(), $payload->toArray());

		if (!$updateResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Job error: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
	): void
	{
		// not implemented yet
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new FillRepeatSaleTipsEvent();
	}
}

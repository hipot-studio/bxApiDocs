<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Main\Result;

final class CallActivityWithAudioRecordingEvent extends AbstractBuilder
{
	private ?int $activityOwnerTypeId = null;
	private ?int $activityId = null;
	private ?int $activityDirection = null;
	private ?int $callDuration = null;

	protected function getTool(): string
	{
		return Dictionary::TOOL_AI;
	}

	protected function customValidate(): Result
	{
		$result = new Result();

		if (!\CCrmOwnerType::IsDefined($this->activityOwnerTypeId))
		{
			$result->addError(
				\Bitrix\Crm\Controller\ErrorCode::getRequiredArgumentMissingError('activityOwnerTypeId')
			);
		}

		if ($this->activityId <= 0)
		{
			$result->addError(
				\Bitrix\Crm\Controller\ErrorCode::getRequiredArgumentMissingError('activityId'),
			);
		}

		if ($this->callDuration === null)
		{
			$result->addError(
				\Bitrix\Crm\Controller\ErrorCode::getRequiredArgumentMissingError('callDuration'),
			);
		}

		if (!\CCrmActivityDirection::IsDefined($this->activityDirection))
		{
			$result->addError(
				\Bitrix\Crm\Controller\ErrorCode::getRequiredArgumentMissingError('activityDirection'),
			);
		}

		return $result;
	}

	protected function buildCustomData(): array
	{
		$this->setSection(Dictionary::SECTION_CRM);
		$this->setSubSection(Dictionary::getAnalyticsEntityType($this->activityOwnerTypeId));

		$this->setP4('callDuration', (string)$this->callDuration);
		$this->setP5('idCall', (string)$this->activityId);

		return [
			'category' => Dictionary::CATEGORY_CRM_OPERATIONS,
			'event' => Dictionary::EVENT_CALL_ACTIVITY_WITH_AUDIO_RECORDING,
			'type' => mb_strtolower(\CCrmActivityDirection::ResolveName($this->activityDirection))
		];
	}

	public function setActivityOwnerTypeId(int $entityTypeId): self
	{
		$this->activityOwnerTypeId = $entityTypeId;

		return $this;
	}

	public function setActivityId(int $activityId): self
	{
		$this->activityId = $activityId;

		return $this;
	}

	public function setActivityDirection(int $directionTypeId): self
	{
		$this->activityDirection = $directionTypeId;

		return $this;
	}

	public function setCallDuration(int $duration): self
	{
		$this->callDuration = $duration;

		return $this;
	}
}

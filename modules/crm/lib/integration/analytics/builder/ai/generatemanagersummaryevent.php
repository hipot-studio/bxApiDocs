<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * A neighbour of @see AIBaseEvent and not its child: the base event requires a CRM owner type, and
 * the target of the manager summary is a user, for which CRM has no owner type. So this event
 * reports the manager id instead of a call id and fills no sub section at all.
 */
final class GenerateManagerSummaryEvent extends AbstractBuilder implements JobFinishEventContract
{
	private string $tool = Dictionary::TOOL_AI;
	private string $category = Dictionary::CATEGORY_CRM_OPERATIONS;
	private string $type = Dictionary::TYPE_MANUAL;

	private ?DateTime $createdTime = null;
	private ?DateTime $finishedTime = null;
	private ?int $operationTypeId = null;
	private ?int $managerId = null;

	public function setTool(string $tool): self
	{
		$this->tool = $tool;

		return $this;
	}

	public function setCategory(string $category): self
	{
		$this->category = $category;

		return $this;
	}

	protected function getTool(): string
	{
		return $this->tool;
	}

	protected function customValidate(): Result
	{
		$result = new Result();

		if (!$this->createdTime || !$this->finishedTime)
		{
			$result->addError(
				ErrorCode::getRequiredArgumentMissingError('createdTime || finishedTime'),
			);
		}

		if ($this->operationTypeId <= 0)
		{
			$result->addError(
				ErrorCode::getRequiredArgumentMissingError('operationTypeId'),
			);
		}

		if ($this->managerId <= 0)
		{
			$result->addError(
				ErrorCode::getRequiredArgumentMissingError('managerId'),
			);
		}

		return $result;
	}

	protected function buildCustomData(): array
	{
		$this->setSection(Dictionary::SECTION_CRM);
		$this->setP2('stepNumber', (string)$this->operationTypeId);

		$durationInSeconds = $this->finishedTime->getTimestamp() - $this->createdTime->getTimestamp();
		$this->setP4('stepDuration', (string)$durationInSeconds);

		$this->setP5('idManager', (string)$this->managerId);

		return [
			'type' => $this->type,
			'category' => $this->category,
			'event' => Dictionary::EVENT_GENERATE_MANAGER_SUMMARY,
		];
	}

	public function setIsManualLaunch(bool $isManualLaunch): self
	{
		$this->type = $isManualLaunch ? Dictionary::TYPE_MANUAL : Dictionary::TYPE_AUTO;

		return $this;
	}

	public function setCreatedTime(DateTime $createdTime): self
	{
		$this->createdTime = $createdTime;

		return $this;
	}

	public function setFinishedTime(DateTime $finishedTime): self
	{
		$this->finishedTime = $finishedTime;

		return $this;
	}

	public function setOperationType(int $operationTypeId): self
	{
		$this->operationTypeId = $operationTypeId;

		return $this;
	}

	public function setTargetId(int $targetId): self
	{
		$this->managerId = $targetId;

		return $this;
	}

	/**
	 * Ignored on purpose: the entity type id behind the job target says "call assessment script" while
	 * the id itself is a user id, and there is no CRM owner type to report for a user.
	 */
	public function setTargetTypeId(int $entityTypeId): self
	{
		return $this;
	}

	/**
	 * Ignored on purpose: only a call target has a provider, and this operation never runs on a call.
	 */
	public function setTargetProvider(string $provider): self
	{
		return $this;
	}
}

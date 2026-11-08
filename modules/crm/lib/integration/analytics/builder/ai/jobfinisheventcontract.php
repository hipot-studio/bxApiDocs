<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\AI;

use Bitrix\Crm\Integration\Analytics\Builder\BuilderContract;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Type\DateTime;

/**
 * What the common AI job finish point needs from the event it sends.
 *
 * Named after the job target and not after a call: an operation runs on a call activity, on a call
 * assessment script, on an entity - or on a user, and then the event carries no CRM entity type at
 * all (@see GenerateManagerSummaryEvent).
 *
 * @see \Bitrix\Crm\Integration\AI\Operation\AbstractOperation::constructJobFinishEventBuilder
 */
interface JobFinishEventContract
{
	public function setOperationType(int $operationTypeId): self;

	public function setCreatedTime(DateTime $createdTime): self;

	public function setFinishedTime(DateTime $finishedTime): self;

	public function setIsManualLaunch(bool $isManualLaunch): self;

	public function setTargetTypeId(int $entityTypeId): self;

	public function setTargetId(int $targetId): self;

	/**
	 * Optional: only a call target has a provider, so this is called for calls and skipped otherwise.
	 */
	public function setTargetProvider(string $provider): self;

	/**
	 * The return type is wider than the contract on purpose: implementations inherit this setter from
	 * AbstractBuilder, which does not implement the contract itself.
	 */
	public function setStatus(?string $status): BuilderContract;

	// the same event is sent twice, the second time with another tool and category
	public function setTool(string $tool): self;

	public function setCategory(string $category): self;

	public function buildEvent(): AnalyticsEvent;
}

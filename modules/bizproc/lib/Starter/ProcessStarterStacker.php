<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Dto\ParentWorkflowDto;
use Bitrix\Bizproc\Starter\Result\StartResult;

final class ProcessStarterStacker
{
	/**
	 * @param AbstractProcessStarter[] $starters
	 */
	public function __construct(private readonly array $starters) {}

	public function setDocument(Document $document): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setDocument($document);
		}

		return $this;
	}

	public function setParameters(Parameters $parameters): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setParameters($parameters);
		}

		return $this;
	}

	public function setUser(int $userId): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setUser($userId);
		}

		return $this;
	}

	public function setMetaData(MetaData $metaData): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setMetaData($metaData);
		}

		return $this;
	}

	public function setParentWorkflow(ParentWorkflowDto $parentWorkflow): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setParentWorkflow($parentWorkflow);
		}

		return $this;
	}

	public function setTemplateIds(array $templateIds): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setTemplateIds($templateIds);
		}

		return $this;
	}

	public function setContext(Context $context): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setContext($context);
		}

		return $this;
	}

	public function setDelay(?int $delay = null): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->setDelay($delay);
		}

		return $this;
	}

	public function setValidateParameters(bool $validateParameters): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->config->validateParameters = $validateParameters;
		}

		return $this;
	}

	public function setCheckConstants(bool $checkConstants): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->config->checkConstants = $checkConstants;
		}

		return $this;
	}

	public function addEvent(Event $event): self
	{
		foreach ($this->starters as $starter)
		{
			$starter->addEvent($event);
		}

		return $this;
	}

	public function run(): StartResult
	{
		$merged = new StartResult();
		$triggerApplied = false;
		foreach ($this->starters as $starter)
		{
			$childResult = $starter->run();
			if (!$childResult->isSuccess())
			{
				$merged->addErrors($childResult->getErrors());
			}
			$merged->addWorkflowIds($childResult->getWorkflowIds());
			$merged->addTemplateWorkflowIds($childResult->getTemplateWorkflowIds());
			$triggerApplied = $triggerApplied || $childResult->isTriggerApplied();
		}
		$merged->setTriggerApplied($triggerApplied);

		return $merged;
	}
}

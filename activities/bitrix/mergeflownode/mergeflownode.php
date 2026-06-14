<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPMergeFlowNode extends CBPActivity
{
	protected array $inputQueue = [];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
		];
	}

	public function executeWithPayload(\Bitrix\Bizproc\Internal\Entity\Workflow\ExecutionPayload $payload)
	{
		$inputLink = $payload->getParentLink();
		$this->inputQueue[$inputLink] = true;

		if ($this->allQueued())
		{
			// reset queue
			$this->inputQueue = [];

			return CBPActivityExecutionStatus::Closed;
		}

		return CBPActivityExecutionStatus::Cancelled;
	}

	protected function allQueued(): bool
	{
		/** @var CBPNodeWorkflowActivity $rootNode */
		$rootNode = $this->getRootActivity();
		$inputNames = $rootNode->getInputNames($this->getName());

		return empty(array_diff($inputNames, array_keys($this->inputQueue)));
	}
}

<?php

namespace Bitrix\Bizproc\Internal\Entity\Workflow;

class TriggerExecutionStageWorkflow extends \CBPWorkflow
{
	public function __construct(\CBPRuntime $runtime)
	{
		$this->runtime = $runtime;
	}

	public function getInstanceId(): string
	{
		return '';
	}

	public function start(): void {}

	public function resume(): void {}

	public function terminate(\Exception $e = null, $stateTitle = ''): void {}
}
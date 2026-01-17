<?php

namespace Bitrix\Bizproc\Internal\Factory\Workflow;

use Bitrix\Bizproc\Internal\Entity\Workflow\TriggerExecutionStageWorkflow;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;

class TriggerStageWorkflowFactory
{
	public function create(int $templateId, array $documentId): TriggerExecutionStageWorkflow
	{
		$workflow = new TriggerExecutionStageWorkflow(\CBPRuntime::getRuntime());
		$workflow->initialize($this->makeRootActivity(), $documentId, [], [], [], $templateId);

		return $workflow;
	}

	private function makeRootActivity(): \CBPActivity
	{
		$code = NodesToTemplate::ROOT_NODE_TYPE;
		if (\CBPActivity::includeActivityFile($code))
		{
			return \CBPActivity::createInstance($code, '');
		}

		throw new \Exception('Node root activity not found');
	}
}
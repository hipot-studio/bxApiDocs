<?php

namespace Bitrix\Bizproc\Internal\Factory\Workflow;

use Bitrix\Bizproc\Internal\Entity\Workflow\TriggerExecutionStageWorkflow;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;

class TriggerStageWorkflowFactory
{
	/**
	 * @param array $parameters Workflow parameters of the stub, the properties of its root activity - the
	 *     narrowing of the document read ({@see \CBPDocument::PARAM_USED_DOCUMENT_FIELDS}) among them.
	 */
	public function create(
		int $templateId,
		array $documentId,
		array $parameters = [],
	): TriggerExecutionStageWorkflow
	{
		$workflow = new TriggerExecutionStageWorkflow(\CBPRuntime::getRuntime());
		$workflow->initialize($this->makeRootActivity(), $documentId, $parameters, [], [], $templateId);

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
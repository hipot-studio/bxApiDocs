<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Workflow;

/**
 * The mark of a test run of a template: the trigger node the developer started the run from and the outputs
 * filled in by hand instead of the event that never happened.
 *
 * It reaches the instance as an argument of the start of its own ({@see \CBPDocument::startWorkflow()},
 * {@see \CBPRuntime::createWorkflow()}) and never as a key of the workflow parameters: the parameters are
 * data of the instance - they come from a REST call and from the parameters the template declares - and data
 * must not turn an ordinary run into a test one.
 *
 * The context lives on the root activity ({@see \CBPActivity::getTestRunContext()}), so it is serialized
 * with the instance and comes back with it after a pause.
 */
final class TestRunContext
{
	/**
	 * @param string $startNodeName Name of the trigger node the run was started from.
	 * @param array $values Outputs to publish for that node, keyed by the name of the output.
	 * @param array $types Field type descriptions of those outputs, keyed the same way.
	 */
	public function __construct(
		public readonly string $startNodeName,
		public readonly array $values = [],
		public readonly array $types = [],
	)
	{
	}

	public function isStartNode(\CBPActivity $activity): bool
	{
		return $activity->getName() === $this->startNodeName;
	}
}

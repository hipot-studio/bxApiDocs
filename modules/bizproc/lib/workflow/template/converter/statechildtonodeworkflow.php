<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

final class StateChildToNodeWorkflow extends SequentialToNodeWorkflow
{
	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct(array $stateActivity)
	{
		if (
			$stateActivity['Type'] !== 'StateInitializationActivity'
			&& $stateActivity['Type'] !== 'StateFinalizationActivity'
			&& $stateActivity['Type'] !== 'EventDrivenActivity'
		)
		{
			throw new \CBPArgumentException(
				'unexpected state activity type ' . $stateActivity['Type']
			);
		}

		$this->rootActivity = $stateActivity;
	}

	protected function createTriggers(): array
	{
		$merge = $this->createMergeNode();
		$this->setPosition($merge['Name'], 1, 1);

		$trigger = $this->rootActivity;

		$this->copyPosition($merge['Name'], $trigger['Name']);
		$this->movePosition($merge['Name'], 0, 1);

		$children = [$merge, $trigger];
		$links = [$this->createLink($trigger['Name'], $merge['Name'])];

		return [$merge['Name'], $links, $children];
	}
}

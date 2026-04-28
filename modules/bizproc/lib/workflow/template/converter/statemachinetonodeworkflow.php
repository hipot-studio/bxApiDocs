<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter;

/** @noinspection AutoloadingIssuesInspection */
final class StateMachineToNodeWorkflow extends SequentialToNodeWorkflow
{
	protected ?bool $shiftStatePos = null;
	protected array $stateNames;

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct(array $template)
	{
		$rootActivity = $template[0] ?? null;

		if (($rootActivity['Type'] ?? '') !== 'StateMachineWorkflowActivity')
		{
			throw new \CBPArgumentException('root activity needs to be a StateMachineWorkflowActivity');
		}

		$this->rootActivity = $rootActivity;
		$this->stateNames = array_column($rootActivity['Children'], 'Name');
		$this->setStartTrigger('ManualStartTrigger');
	}

	protected function convertChild(string $outputName, array $child): array
	{
		if ($child['Type'] === 'StateActivity')
		{
			return $this->convertStateActivity($outputName, $child);
		}

		return parent::convertChild($outputName, $child);
	}

	private function convertStateActivity(string $outputName, array $activity): array
	{
		$isFirstState = $this->shiftStatePos === null;
		$this->shiftStatePos ??= false;

		$this->setChildPositionNextRow($outputName, $activity['Name']);
		if (!$isFirstState)
		{
			$this->setChildPositionNextColumn($outputName, $activity['Name']);
		}

		[$links, $children] = $this->convertStateChildren($activity);
		array_unshift($children, $activity);

		if ($isFirstState)
		{
			array_unshift($links, $this->createLink($outputName, $activity['Name']));
		}

		return [
			$activity['Name'],
			$links,
			$children,
		];
	}

	private function convertStateChildren(array $state): array
	{
		$links = [];
		$children = [];

		$parentRow = $state['Name'];

		foreach ($state['Children'] as $stateChild)
		{
			[$childLinks, $child] = $this->convertStateChild($stateChild);
			$this->setChildPositionNextRow($parentRow, $child['Name']);

			array_push($links, ...$childLinks);
			$links[] = $this->createLink($state['Name'] . ':a0', $child['Name'] . ':t0');
			$children[] = $child;

			$parentRow = $child['Name'];
		}


		return [$links, $children];
	}

	private function convertStateChild(array $child): array
	{
		if ($child['Type'] === 'EventDrivenActivity')
		{
			$isDelay = ($child['Children'][0]['Type'] ?? '') === 'DelayActivity';
			$child['PresetId'] = $isDelay ? 'DELAY' : 'CMD';
			$child['Properties']['Title'] = $child['Children'][0]['Properties']['Title'];
		}

		if (
			$child['Type'] === 'StateInitializationActivity'
			|| $child['Type'] === 'StateFinalizationActivity'
			|| $child['Type'] === 'EventDrivenActivity'
		)
		{
			$links = $this->createStateChildLinks($child, $child['Name']);

			//todo: proto
			$child['Children'] = (new StateChildToNodeWorkflow($child))->convert();

			return [$links, $child];
		}

		throw new \CBPArgumentException('unexpected child activity type in StateActivity: ' . $child['Type']);
	}

	private function createStateChildLinks(array $child, string $outputName): array
	{
		$links = [];
		foreach ($this->walkChildren($child) as $activity)
		{
			if ($activity['Type'] === 'SetStateActivity')
			{
				$targetState = $activity['Properties']['TargetStateName'] ?? null;
				if ($targetState && in_array($targetState, $this->stateNames, true))
				{
					$links[] = $this->createLink($outputName, $targetState);
				}
			}
		}

		return $links;
	}

	private function walkChildren(array $activity): iterable
	{
		yield $activity;

		if (is_array($activity['Children'] ?? null))
		{
			foreach ($activity['Children'] as $child)
			{
				foreach ($this->walkChildren($child) as $descendant)
				{
					yield $descendant;
				}
			}
		}
	}

	protected function makeNodeSettings(array $activity): array
	{
		$settings = parent::makeNodeSettings($activity);
		if ($activity['Type'] === 'StateActivity')
		{
			$settings['ports'] = [
				'input' => [['id' => 'i0']],
				'aux' => [['id' => 'a0']],
			];
			$settings['dimensions'] = [
				'width' => 240,
				'height' => null,
			];
		}
		if (
			$activity['Type'] === 'StateInitializationActivity'
			|| $activity['Type'] === 'StateFinalizationActivity'
			|| $activity['Type'] === 'EventDrivenActivity'
		)
		{
			$settings['ports'] = [
				'output' => [['id' => 'o0']],
				'topAux' => [['id' => 't0']],
			];
		}

		return $settings;
	}
}

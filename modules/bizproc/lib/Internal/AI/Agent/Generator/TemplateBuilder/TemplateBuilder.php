<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ConditionConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\FlowConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

final class TemplateBuilder
{
	private const SETUP_ACTIVITY = 'SetupTemplateActivity';
	private const ROOT_ACTIVITY = 'NodeWorkflowActivity';
	private const ROOT_NAME = 'Template';

	private LayoutEngine $layout;
	private LinkBuilder $links;
	private array $activities = [];
	private AgentConfig $currentConfig;
	private string $langPrefix = '';
	private array $unconnectedOutputs = [];
	private ?string $currentTriggerName = null;

	public function __construct(
		private readonly ActivityRegistry $registry,
		private readonly ActivityNodeBuilder $nodeBuilder,
	)
	{
	}

	/**
	 * @return array{NAME: string, DESCRIPTION: string, PARAMETERS: array, VARIABLES: array, CONSTANTS: array, TEMPLATE: array}
	 */
	public function build(AgentConfig $config): array
	{
		// registry is cache-only; nodeBuilder reuses registry cache but resets generated IDs below
		$this->nodeBuilder->reset();
		$this->activities = [];
		$this->links = new LinkBuilder();
		$this->layout = new LayoutEngine();
		$this->unconnectedOutputs = [];
		$this->currentTriggerName = null;
		$this->langPrefix = strtoupper(str_replace(' ', '_', $config->name)) . '_';
		$this->currentConfig = $config;

		foreach ($config->flows as $flow)
		{
			$this->buildFlow($flow);
			$this->layout->nextFlow();
		}

		return [
			'NAME' => $this->wrapLangKey($config->title),
			'DESCRIPTION' => $this->wrapLangKey($config->description),
			'PARAMETERS' => [],
			'VARIABLES' => [],
			'CONSTANTS' => $this->buildConstants($config->constants),
			'TEMPLATE' => [
				[
					'Type' => self::ROOT_ACTIVITY,
					'Name' => self::ROOT_NAME,
					'Properties' => [
						'Title' => $this->wrapLangKey($config->title),
						'Links' => $this->links->getLinks(),
					],
					'Children' => $this->activities,
				],
			],
		];
	}

	private function buildConstants(array $constants): array
	{
		$result = [];

		foreach ($constants as $key => $constant)
		{
			$entry = [
				'Name' => $this->wrapLangKey($constant->label),
				'Description' => '',
				'Type' => $constant->type,
				'Required' => $constant->required ? 1 : 0,
				'Multiple' => $constant->multiple ? 1 : 0,
				'Options' => null,
				'Default' => $constant->default ?? '',
			];

			if (!empty($constant->options))
			{
				$options = [];
				foreach ($constant->options as $value => $labelKey)
				{
					$options[(string)$value] = $this->wrapLangKey($labelKey);
				}
				$entry['Options'] = $options;
			}

			$result[$key] = $entry;
		}

		return $result;
	}

	private function buildFlow(FlowConfig $flow): void
	{
		$this->unconnectedOutputs = [];
		$stepIndex = 0;

		$triggerType = $this->registry->resolveActivityType($flow->trigger);
		$triggerPosition = $this->layout->calculatePosition($stepIndex);
		$triggerProps = $flow->triggerProps;
		$triggerProps['Title'] = $this->getActivityTitle($triggerType);
		$triggerNode = $this->nodeBuilder->build(
			$triggerType,
			$triggerProps,
			$triggerPosition,
			$flow->triggerId,
		);
		$this->activities[] = $triggerNode;
		$this->currentTriggerName = $triggerNode['Name'];
		$previous = NodeOutput::sequential($triggerNode['Name']);
		$stepIndex++;

		foreach ($flow->steps as $step)
		{
			$previous = $this->buildStep($step, $stepIndex, $previous);
			$stepIndex++;
		}
	}

	private function buildStep(StepConfig $step, int &$stepIndex, NodeOutput $previous): NodeOutput
	{
		if ($step->isCondition)
		{
			return $this->buildCondition($step, $stepIndex, $previous);
		}

		if ($step->isComposite)
		{
			return $this->buildComposite($step, $stepIndex, $previous);
		}

		$activityType = $this->registry->resolveActivityType($step->type);
		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);

		if ($activityType === self::SETUP_ACTIVITY)
		{
			$properties = $this->buildSetupTemplateProperties($properties);
		}

		$node = $this->nodeBuilder->build($activityType, $properties, $position, $step->id);
		$this->activities[] = $node;
		$this->connectAllTerminals($previous, $node['Name']);

		$output = NodeOutput::sequential($node['Name']);
		$this->unconnectedOutputs = [$output];

		return $output;
	}

	private function connectNodes(NodeOutput $from, NodeInput $to): void
	{
		$this->links->connect($from, $to);
	}

	/**
	 * Connects all unconnected outputs from previous step to next node's standard input.
	 * If previous step was a condition, connects both true and false branch endpoints.
	 */
	private function connectAllTerminals(NodeOutput $primary, string $nextName): void
	{
		$to = NodeInput::standard($nextName);
		$this->connectNodes($primary, $to);

		foreach ($this->unconnectedOutputs as $terminal)
		{
			if ($terminal->name !== $primary->name || $terminal->port !== $primary->port)
			{
				$this->connectNodes($terminal, $to);
			}
		}
	}

	private function buildCondition(StepConfig $step, int &$stepIndex, NodeOutput $previous): NodeOutput
	{
		$activityType = ActivityRegistry::CONDITION_ACTIVITY_TYPE;
		$position = $this->layout->calculatePosition($stepIndex);

		$conditions = array_map(
			fn(ConditionConfig $c) => $c->toArray(),
			$step->conditions,
		);

		$properties = [
			'Title' => $this->getActivityTitle($activityType),
			'mixedcondition' => $conditions,
		];

		$condNode = $this->nodeBuilder->build($activityType, $properties, $position, $step->id);
		$this->activities[] = $condNode;
		$this->connectNodes($previous, NodeInput::standard($condNode['Name']));

		$condName = $condNode['Name'];
		$trueBranch = $this->buildBranch($step->trueBranch, NodeOutput::conditionTrue($condName), $stepIndex + 1);

		$this->layout->shiftRow();
		$falseBranch = $this->buildBranch($step->falseBranch, NodeOutput::conditionFalse($condName), $stepIndex + 1);

		$this->layout->resetRow();
		$stepIndex = max($trueBranch->maxStepIndex, $falseBranch->maxStepIndex);

		$this->unconnectedOutputs = array_merge($trueBranch->unconnectedOutputs, $falseBranch->unconnectedOutputs);

		return $trueBranch->output;
	}

	private function getActivityTitle(string $activityType): string
	{
		$langKey = $this->langPrefix . strtoupper($activityType) . '_TITLE';

		return '###' . $langKey . '###';
	}

	/**
	 * Builds a composite activity (ForEach, etc.) with child activities.
	 * ForEach links: previous→i0, o0→firstChild, lastChild→i1, o1→next
	 */
	private function buildComposite(StepConfig $step, int &$stepIndex, NodeOutput $previous): NodeOutput
	{
		$activityType = $this->registry->resolveActivityType($step->type);
		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);

		$compositeNode = $this->nodeBuilder->build($activityType, $properties, $position, $step->id);
		$this->activities[] = $compositeNode;
		$this->connectNodes($previous, NodeInput::standard($compositeNode['Name']));

		$compositeName = $compositeNode['Name'];

		$childBranch = $this->buildBranch($step->childSteps, NodeOutput::sequential($compositeName), $stepIndex + 1);

		// Connect unconnected outputs back to composite return port (i1)
		$returnInput = NodeInput::compositeReturn($compositeName);
		if (!empty($childBranch->unconnectedOutputs))
		{
			foreach ($childBranch->unconnectedOutputs as $terminal)
			{
				$this->connectNodes($terminal, $returnInput);
			}
		}
		else
		{
			$this->connectNodes(NodeOutput::sequential($compositeName), $returnInput);
		}

		$this->unconnectedOutputs = [];
		$stepIndex = $childBranch->maxStepIndex;

		return NodeOutput::compositeExit($compositeName);
	}

	/** @param StepConfig[] $steps */
	private function buildBranch(array $steps, NodeOutput $entryOutput, int $startStepIndex): BranchResult
	{
		$this->unconnectedOutputs = [];
		$current = $entryOutput;
		$branchStepIndex = $startStepIndex;

		foreach ($steps as $branchStep)
		{
			$current = $this->buildStep($branchStep, $branchStepIndex, $current);
			$branchStepIndex++;
		}

		$unconnected = !empty($this->unconnectedOutputs)
			? $this->unconnectedOutputs
			: [$current];

		return new BranchResult(
			output: $current,
			unconnectedOutputs: $unconnected,
			maxStepIndex: $branchStepIndex - 1,
		);
	}

	private function buildSetupTemplateProperties(array $existingProps): array
	{
		$items = new SetupTemplateActivity\ItemCollection();

		$items->add(new SetupTemplateActivity\Title(
			text: $this->wrapLangKey($this->currentConfig->wizardTitle ?? $this->currentConfig->title),
		));
		$items->add(new SetupTemplateActivity\Description(
			text: $this->wrapLangKey($this->currentConfig->wizardDescription ?? $this->currentConfig->description),
		));

		foreach ($this->currentConfig->constants as $key => $constant)
		{
			if (!$constant->showInWizard)
			{
				continue;
			}

			$options = [];
			foreach ($constant->options as $value => $labelKey)
			{
				$options[] = [
					'value' => (string)$value,
					'name' => $this->wrapLangKey($labelKey),
				];
			}

			$items->add(new SetupTemplateActivity\Constant(
				id: $key,
				name: $this->wrapLangKey($constant->label),
				constantType: $constant->type,
				multiple: $constant->multiple,
				required: $constant->required,
				options: $options,
				default: $constant->default ?? '',
			));
		}

		if ($this->currentTriggerName === null)
		{
			throw new \LogicException('SetupTemplateActivity requires a trigger in the flow');
		}

		$block = new SetupTemplateActivity\Block(items: $items);

		$properties = $existingProps;
		$properties['user'] = '{=' . $this->currentTriggerName . ':startedBy}';
		$properties['blocks'] = [$block->toArray()];

		return $properties;
	}

	private function wrapLangKey(string $key): string
	{
		return '###' . $key . '###';
	}
}

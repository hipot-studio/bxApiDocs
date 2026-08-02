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
		$triggerProps['Title'] = $triggerProps['Title'] ?? $this->getActivityTitle($triggerType);
		$triggerNodeTitle = $this->takeNodeTitle($triggerProps);
		$triggerNode = $this->nodeBuilder->build(
			$triggerType,
			$triggerProps,
			$triggerPosition,
			$flow->triggerId,
			$triggerNodeTitle,
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

		if ($step->isBranches)
		{
			return $this->buildBranches($step, $stepIndex, $previous);
		}

		$activityType = $this->registry->resolveActivityType($step->type);

		if ($this->registry->isComplexWrapper($activityType))
		{
			return $this->buildComplexWrapperStep($step, $stepIndex, $previous);
		}

		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);

		if ($activityType === ActivityRegistry::SETUP_ACTIVITY_TYPE)
		{
			$properties = $this->buildSetupTemplateProperties($properties);
		}

		$node = $this->nodeBuilder->build($activityType, $properties, $position, $step->id, $nodeTitle);
		$this->activities[] = $node;
		$this->connectAllTerminals($previous, $node['Name']);

		$output = NodeOutput::sequential($node['Name']);
		$this->unconnectedOutputs = [$output];

		return $output;
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	private function takeNodeTitle(array &$properties): ?string
	{
		$title = $properties['NodeTitle'] ?? null;
		unset($properties['NodeTitle']);

		return is_string($title) ? $title : null;
	}

	private function getActivityTitle(string $activityType): string
	{
		return '###' . $this->langPrefix . strtoupper($activityType) . '_TITLE###';
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

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);
		$properties['mixedcondition'] = $conditions;

		$condNode = $this->nodeBuilder->build($activityType, $properties, $position, $step->id, $nodeTitle);
		$this->activities[] = $condNode;
		$this->connectAllTerminals($previous, $condNode['Name']);

		$condName = $condNode['Name'];
		$trueBranch = $this->buildBranch($step->trueBranch, NodeOutput::conditionTrue($condName), $stepIndex + 1);

		$this->layout->shiftRow();
		$falseBranch = $this->buildBranch($step->falseBranch, NodeOutput::conditionFalse($condName), $stepIndex + 1);

		$this->layout->resetRow();
		$stepIndex = max($trueBranch->maxStepIndex, $falseBranch->maxStepIndex);

		$this->unconnectedOutputs = array_merge($trueBranch->unconnectedOutputs, $falseBranch->unconnectedOutputs);

		return $trueBranch->output;
	}

	/**
	 * Builds a multi-output activity (e.g. AiAssistantAgentComplexActivity) with arbitrary port branches.
	 * For each branch port (o0, o1, ...), connects port→branchStart, and collects tail outputs.
	 */
	private function buildBranches(StepConfig $step, int &$stepIndex, NodeOutput $previous): NodeOutput
	{
		$activityType = $this->registry->resolveActivityType($step->type);
		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);

		$node = $this->nodeBuilder->build($activityType, $properties, $position, $step->id, $nodeTitle);

		$innerNames = [];
		foreach ($this->iterateRuleExpressions($properties['Rules'] ?? null, 'action') as $expr)
		{
			$activityData = $expr['activityData'] ?? null;
			if (is_array($activityData) && !empty($activityData['Name']))
			{
				$innerNames[] = $activityData['Name'];
				$node['Children'][] = $activityData;
			}
		}
		$this->validateInnerNamesMapping($step->id ?? '?', $innerNames, $properties);

		$existingPortIds = array_column($node['Node']['ports'] ?? [], 'id');
		foreach (array_keys($step->branches) as $port)
		{
			if (in_array($port, $existingPortIds, true))
			{
				continue;
			}
			$title = $this->findPortTitleInRules($properties['Rules'] ?? null, $port)
				?? $this->defaultBranchPortTitle($port);
			$node['Node']['ports'][] = [
				'id' => $port,
				'title' => $title,
				'type' => 'output',
				'isActive' => true,
			];
		}

		$this->activities[] = $node;
		$this->connectAllTerminals($previous, $node['Name']);

		$nodeName = $node['Name'];
		$tails = [];
		$maxStepIndex = $stepIndex;
		$primaryOutput = null;
		$first = true;

		foreach ($step->branches as $port => $branchSteps)
		{
			if (!$first)
			{
				$this->layout->shiftRow();
			}
			$branch = $this->buildBranch($branchSteps, new NodeOutput($nodeName, $port), $stepIndex + 1);
			$tails = array_merge($tails, $branch->unconnectedOutputs);
			$maxStepIndex = max($maxStepIndex, $branch->maxStepIndex);
			$primaryOutput ??= $branch->output;
			$first = false;
		}

		$this->layout->resetRow();
		$stepIndex = $maxStepIndex;
		$this->unconnectedOutputs = $tails;

		// If all branches are empty, fall back to the first declared port — never hardcode o0,
		// because the activity may not even have an o0 output.
		if ($primaryOutput !== null)
		{
			return $primaryOutput;
		}
		$ports = array_keys($step->branches);
		$primaryPort = $ports[0] ?? 'o0';

		return new NodeOutput($nodeName, $primaryPort);
	}

	/**
	 * @param list<string> $innerNames
	 * @param array<string, mixed> $properties
	 */
	private function validateInnerNamesMapping(string $stepId, array $innerNames, array $properties): void
	{
		$refs = [];
		foreach ((array)($properties['InputNames'] ?? []) as $ref)
		{
			$name = strstr((string)$ref, ':', true);
			if ($name !== false && $name !== '')
			{
				$refs[$name] = 'InputNames';
			}
		}
		foreach (array_keys((array)($properties['OutputNames'] ?? [])) as $ref)
		{
			$name = strstr((string)$ref, ':', true);
			if ($name !== false && $name !== '')
			{
				$refs[$name] = $refs[$name] ?? 'OutputNames';
			}
		}
		foreach ($refs as $refName => $where)
		{
			if (!in_array($refName, $innerNames, true))
			{
				throw new \InvalidArgumentException(sprintf(
					"Complex activity '%s': %s references '%s' which is not declared as inner activity in Rules. Inner activities found: [%s]",
					$stepId,
					$where,
					$refName,
					implode(', ', $innerNames),
				));
			}
		}
	}

	private function defaultBranchPortTitle(string $portId): string
	{
		if (preg_match('/^o(\d+)$/', $portId, $m))
		{
			return 'O' . ((int)$m[1] + 1);
		}

		throw new \LogicException("Branch port '$portId' must match /^o\\d+\$/ (validated upstream in StepConfig)");
	}

	private function findPortTitleInRules(mixed $rules, string $portId): ?string
	{
		foreach ($this->iterateRuleExpressions($rules, 'output') as $expr)
		{
			if (($expr['portId'] ?? null) === $portId && isset($expr['title']))
			{
				return (string)$expr['title'];
			}
		}

		return null;
	}

	/**
	 * @return \Generator<array<string, mixed>>
	 */
	private function iterateRuleExpressions(mixed $rules, string $constructionType): \Generator
	{
		if (!is_array($rules))
		{
			return;
		}
		foreach ($rules as $inputPortRules)
		{
			$cards = is_array($inputPortRules) ? ($inputPortRules['ruleCards'] ?? []) : [];
			foreach ($cards as $card)
			{
				$constructions = is_array($card) ? ($card['constructions'] ?? []) : [];
				foreach ($constructions as $constr)
				{
					if (!is_array($constr) || ($constr['type'] ?? null) !== $constructionType)
					{
						continue;
					}
					$expr = $constr['expression'] ?? null;
					if (is_array($expr))
					{
						yield $expr;
					}
				}
			}
		}
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
		$nodeTitle = $this->takeNodeTitle($properties);

		$compositeNode = $this->nodeBuilder->build($activityType, $properties, $position, $step->id, $nodeTitle);
		$this->activities[] = $compositeNode;
		$this->connectAllTerminals($previous, $compositeNode['Name']);

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

		if ($activityType === ActivityRegistry::FOREACH_ACTIVITY_TYPE)
		{
			$this->layout->reserveCompositeLoopback();
		}

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

	private function buildComplexWrapperStep(StepConfig $step, int &$stepIndex, NodeOutput $previous): NodeOutput
	{
		if ($step->innerId === null)
		{
			throw new \InvalidArgumentException(
				"Complex wrapper '{$step->type}' requires '_inner_id' in template.source.json",
			);
		}

		if ($step->innerType === null)
		{
			throw new \InvalidArgumentException(
				"Complex wrapper '{$step->type}' requires '_inner_type' in template.source.json",
			);
		}

		$outerType = $this->registry->resolveActivityType($step->type);
		$innerType = $step->innerType;
		$innerId = $step->innerId;
		$auxPort = $this->registry->getAuxPort($outerType);
		$position = $this->layout->calculatePosition($stepIndex);
		$outerTitle = $this->getActivityTitle($outerType);

		$innerProps = $step->props;
		$innerProps['Title'] = $innerProps['Title'] ?? $outerTitle;
		$innerProps['EditorComment'] = '';
		if ($auxPort !== null)
		{
			$innerProps['auxPort'] = $auxPort;
		}

		$outerIdForPrefix = $step->id ?? 'A0000_0000_0000_0000';
		[$cardId, $actionId, $outputId] = $this->generateComplexNodeRuleIds($outerIdForPrefix);

		$actionExpression = [
			'actionId' => $innerType,
			'rawActivityData' => null,
			'activityData' => [
				'Name' => $innerId,
				'Type' => $innerType,
				'Activated' => 'Y',
				'Properties' => $innerProps,
				'ReturnProperties' => $this->buildInnerReturnProperties($outerType, $innerType),
				'Document' => null,
			],
			'document' => null,
		];

		if ($auxPort !== null)
		{
			$actionExpression['auxPortId'] = $auxPort;
			$actionExpression['auxPortTitle'] = 'T1';
		}

		$rules = [
			'i0' => [
				'portId' => 'i0',
				'ruleCards' => [
					[
						'id' => $cardId,
						'constructions' => [
							[
								'id' => $actionId,
								'type' => 'action',
								'expression' => $actionExpression,
							],
							[
								'id' => $outputId,
								'type' => 'output',
								'expression' => ['portId' => 'o1', 'title' => 'E1'],
							],
						],
						'isFilled' => true,
					],
				],
			],
		];

		$returnProperties = $this->buildInnerReturnProperties($outerType, $innerType);

		$outerProperties = [
			'Title' => $outerTitle,
			'EditorComment' => '',
			'Rules' => $rules,
			'InputNames' => [$innerId . ':i0'],
			'OutputNames' => [$innerId . ':o0' => 1],
			'Links' => [],
		];

		$node = $this->nodeBuilder->build($outerType, $outerProperties, $position, $step->id);
		$node['Node']['ports'] = $this->buildComplexWrapperPorts($node['Node']['ports'] ?? []);
		$node['Node']['dimensions']['height'] = 291;
		$node['Children'] = [
			[
				'Name' => $innerId,
				'Type' => $innerType,
				'Activated' => 'Y',
				'Properties' => $innerProps,
				'ReturnProperties' => $returnProperties,
				'Document' => null,
			],
		];

		$this->activities[] = $node;
		$this->connectAllTerminals($previous, $node['Name']);

		$output = NodeOutput::compositeExit($node['Name']);
		$this->unconnectedOutputs = [$output];

		return $output;
	}

	private function buildComplexWrapperPorts(array $ports): array
	{
		$result = [];
		foreach ($ports as $port)
		{
			$result[] = $port;
			if ($port['id'] === 'i0')
			{
				$result[] = ['id' => 'o1', 'title' => 'E1', 'type' => 'output', 'isActive' => true];
			}
		}

		return $result;
	}

	/**
	 * Generates 3 deterministic IDs for rule card, action construction, output construction.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function generateComplexNodeRuleIds(string $outerId): array
	{
		if (preg_match('/^A(\d{4})_(\d{4})_(\d{4})_(\d{4})$/', $outerId, $m))
		{
			[, $g1, $g2, $g3] = $m;
		}
		else
		{
			[$g1, $g2, $g3] = ['0000', '0000', '0000'];
		}

		return [
			sprintf('A%s_%s_%s_8001', $g1, $g2, $g3),
			sprintf('A%s_%s_%s_8002', $g1, $g2, $g3),
			sprintf('A%s_%s_%s_8003', $g1, $g2, $g3),
		];
	}

	private function buildInnerReturnProperties(string $complexType, string $innerType): array
	{
		$defs = $this->registry->getReturnPropertyDefinitions($innerType);
		if ($defs === null)
		{
			return [];
		}

		$prefix = $this->langPrefix . strtoupper($complexType) . '_';
		$result = [];

		foreach ($defs as $id => $def)
		{
			$type = is_string($def['TYPE'] ?? null) ? $def['TYPE'] : 'string';
			$langKeySuffix = strtoupper(preg_replace('/([A-Z])/', '_$1', (string)$id));
			$result[] = [
				'Id' => $id,
				'Type' => $type,
				'BaseType' => null,
				'Name' => '###' . $prefix . 'RETURN_' . $langKeySuffix . '###',
				'Description' => null,
				'Multiple' => false,
				'Required' => false,
				'Options' => null,
				'Settings' => null,
				'Default' => null,
			];
		}

		return $result;
	}

	private function buildSetupTemplateProperties(array $existingProps): array
	{
		$blocks = [];
		$currentItems = new SetupTemplateActivity\ItemCollection();

		$currentItems->add(new SetupTemplateActivity\Title(
			text: $this->wrapLangKey($this->currentConfig->wizardTitle ?? $this->currentConfig->title),
		));
		$currentItems->add(new SetupTemplateActivity\Description(
			text: $this->wrapLangKey($this->currentConfig->wizardDescription ?? $this->currentConfig->description),
		));

		foreach ($this->currentConfig->constants as $key => $constant)
		{
			if (!$constant->showInWizard)
			{
				continue;
			}

			if ($constant->wizardTitle !== null)
			{
				$blocks[] = (new SetupTemplateActivity\Block(items: $currentItems))->toArray();
				$currentItems = new SetupTemplateActivity\ItemCollection();
				$currentItems->add(new SetupTemplateActivity\Title(
					text: $this->wrapLangKey($constant->wizardTitle),
				));
				if ($constant->wizardDescription !== null)
				{
					$currentItems->add(new SetupTemplateActivity\Description(
						text: $this->wrapLangKey($constant->wizardDescription),
					));
				}
			}

			$options = [];
			foreach ($constant->options as $value => $labelKey)
			{
				$options[] = [
					'value' => (string)$value,
					'name' => $this->wrapLangKey($labelKey),
				];
			}

			$currentItems->add(new SetupTemplateActivity\Constant(
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

		$blocks[] = (new SetupTemplateActivity\Block(items: $currentItems))->toArray();

		$properties = $existingProps;
		$properties['user'] = '{=' . $this->currentTriggerName . ':startedBy}';
		$properties['blocks'] = $blocks;

		return $properties;
	}

	private function wrapLangKey(string $key): string
	{
		return '###' . $key . '###';
	}
}

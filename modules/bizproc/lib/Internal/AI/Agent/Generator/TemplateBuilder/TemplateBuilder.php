<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ActivityDescriptorConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ComplexActivityRules;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ConditionConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\FlowConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;

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
	private bool $hasSetupTemplateActivity = false;
	/** @var array<string, true> node IDs the reference steps of this build point to */
	private array $referencedNodeIds = [];

	/**
	 * What every link of the build uses its two endpoints as, in the order the links were made: an entry per
	 * link, appended by connectNodes() beside the link itself. A link carries its endpoints as the strings the
	 * template holds - "node:port" and nothing more - so what the construct behind the link meant the port to
	 * be is kept here until assertLinkEndpointsExist() holds it against the node.
	 *
	 * @var list<array{ActivityPortType, ActivityPortType}>
	 */
	private array $linkPortTypes = [];

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
		// Every name the document states is claimed before the first node is built, so that a step without an
		// '_id' cannot be given a name a step further down states - see ActivityNodeBuilder::reserveNames().
		$this->nodeBuilder->reserveNames($config->declaredNodeIds());
		$this->activities = [];
		$this->links = new LinkBuilder();
		$this->layout = new LayoutEngine();
		$this->unconnectedOutputs = [];
		$this->currentTriggerName = null;
		$this->hasSetupTemplateActivity = false;
		$this->referencedNodeIds = [];
		$this->linkPortTypes = [];
		$this->langPrefix = strtoupper(str_replace(' ', '_', $config->name)) . '_';
		$this->currentConfig = $config;

		foreach ($config->flows as $flow)
		{
			$this->buildFlow($flow);
			$this->layout->nextFlow();
		}

		$this->assertReferencesResolved();
		$this->assertLinkEndpointsExist();

		return [
			'NAME' => $this->wrapLangKey($config->title),
			'DESCRIPTION' => $this->wrapLangKey($config->description),
			// Parameters and variables are data of the template and carry no lang keys of the agent: the source
			// states them the way the template holds them, and a source that states none leaves both empty.
			'PARAMETERS' => $config->parameters,
			'VARIABLES' => $config->variables,
			'CONSTANTS' => $this->buildConstants($config->constants),
			'TEMPLATE' => [
				[
					'Type' => self::ROOT_ACTIVITY,
					'Name' => self::ROOT_NAME,
					'Properties' => [
						// The root activity carries a title of its own where the source states one; a source that
						// states none names it after the agent, the way the build always did.
						'Title' => $this->wrapLangKey($config->rootTitle ?? $config->title),
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
				// A constant without a hint carries an empty description, the way the designer writes it.
				'Description' => $this->wrapOptionalLangKey($constant->description),
				'Type' => $constant->type,
				'Required' => $constant->required ? 1 : 0,
				'Multiple' => $constant->multiple ? 1 : 0,
				// A constant the source states no options for carries null there, the way the build always wrote
				// it; a constant stating an empty map carries an empty one. Both stand in the shipped bytes.
				'Options' => $constant->options === null ? null : $this->wrapOptions($constant->options),
				'Default' => $constant->default,
			];
			if ($this->hasSetupTemplateActivity && $constant->showInWizard)
			{
				$entry['Source'] = SetupTemplateService::SETUP_CONSTANT_SOURCE;
			}

			// The settings of a field type are written only where the source states them - an empty map included:
			// a constant the source says nothing about has no such key in the template at all, and writing one
			// would move the shipped bytes.
			if ($constant->settings !== null)
			{
				$entry['Settings'] = $constant->settings;
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
			$this->resolveNodeDescriptor($triggerType, $flow->triggerNodeDescriptor),
			activityTail: $flow->triggerActivityTail,
		);
		$this->addActivity($triggerNode);
		$this->currentTriggerName = $triggerNode['Name'];
		$previous = NodeOutput::sequential($triggerNode['Name']);
		$stepIndex++;

		if (!empty($flow->fanout))
		{
			$this->walkPortBranches($triggerNode['Name'], [$previous->port => $flow->fanout], $stepIndex);

			return;
		}

		foreach ($flow->steps as $step)
		{
			$previous = $this->buildStep($step, $stepIndex, $previous);
			$stepIndex++;
		}
	}

	/**
	 * @param NodeOutput|null $previous continuation of the branch, null when a reference has closed it
	 * @return NodeOutput|null null when this step closes the branch
	 */
	private function buildStep(StepConfig $step, int &$stepIndex, ?NodeOutput $previous): ?NodeOutput
	{
		if ($step->refId !== null)
		{
			return $this->buildReference($step->refId, $previous);
		}

		if ($step->isCondition)
		{
			return $this->buildCondition($step, $stepIndex, $previous);
		}

		if ($step->isComposite)
		{
			return $this->buildComposite($step, $stepIndex, $previous);
		}

		if ($step->isBranches || $step->isFanout)
		{
			return $this->buildBranches($step, $stepIndex, $previous);
		}

		$activityType = $this->registry->resolveActivityType($step->type);

		// The step itself is asked first: a wrapper whose module is not deployed here answers the registry as no
		// wrapper at all, and the shorthand of the step - already cut out of the properties as a reserved key -
		// would then be gone from the node together with the activity it names.
		if ($step->hasInnerActivityShorthand() || $this->registry->isComplexWrapper($activityType))
		{
			return $this->buildComplexWrapperStep($step, $stepIndex, $previous);
		}

		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);
		$properties = $this->applySetupTemplateProperties($activityType, $properties);

		$node = $this->nodeBuilder->build(
			$activityType,
			$properties,
			$position,
			$step->id,
			$nodeTitle,
			$this->resolveNodeDescriptor($activityType, $step->nodeDescriptor),
			activityTail: $step->activityTail,
		);
		$this->addStepNode($node, $step, $stepIndex);
		$this->connectAllTerminals($previous, $node['Name']);

		$output = NodeOutput::sequential($node['Name']);
		$this->unconnectedOutputs = [$output];

		return $output;
	}

	/**
	 * Puts the node of a step into the template together with the nodes its 'attachments' bind to the aux
	 * ports of that node. A bound node is a top-level activity of its own - a knowledge base, a set of
	 * tools the activity uses - wired 'owner:auxPort -> bound:topAuxPort'. It takes no part in the chain:
	 * nothing leads out of it, and the cursor of the chain does not move because of it.
	 */
	private function addStepNode(array $node, StepConfig $step, int $stepIndex): void
	{
		foreach (array_keys($step->attachments) as $auxPort)
		{
			self::addPort($node, (string)$auxPort, ActivityPortType::Aux);
		}
		$this->addActivity($node);

		foreach ($step->attachments as $auxPort => $boundSteps)
		{
			foreach ($boundSteps as $boundStep)
			{
				// A bound node is drawn in a row of its own, the way a branch is: it shares the column of
				// the node it hangs on, so laying it out at the same step index would cover that node.
				$this->layout->shiftRow($stepIndex);
				$boundNode = $this->buildBoundNode($boundStep, $stepIndex);
				$this->layout->resetRow();

				$this->addActivity($boundNode);
				$this->connectNodes(
					new NodeOutput($node['Name'], (string)$auxPort),
					new NodeInput($boundNode['Name'], $this->findTopAuxPort($boundNode)),
					ActivityPortType::Aux,
					ActivityPortType::TopAux,
				);
			}
		}
	}

	/**
	 * Puts a built node into the template and tells the layout the space it takes on the canvas: rows are
	 * drawn below the nodes standing in their columns, so a node has to be counted before the next row.
	 */
	private function addActivity(array $node): void
	{
		$this->activities[] = $node;

		$canvas = $node['Node'] ?? [];
		$position = $canvas['position'] ?? [];
		$dimensions = $canvas['dimensions'] ?? [];
		$this->layout->noteNode(
			new Position((int)($position['x'] ?? 0), (int)($position['y'] ?? 0)),
			$dimensions['width'] ?? null,
			$dimensions['height'] ?? null,
		);
	}

	private function buildBoundNode(StepConfig $step, int $stepIndex): array
	{
		$activityType = $this->registry->resolveActivityType($step->type);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);

		return $this->nodeBuilder->build(
			$activityType,
			$properties,
			$this->layout->calculatePosition($stepIndex),
			$step->id,
			$nodeTitle,
			$this->resolveNodeDescriptor($activityType, $step->nodeDescriptor),
			activityTail: $step->activityTail,
		);
	}

	/**
	 * The port a binding enters: the first port of type 'topAux' the bound node declares. It comes from
	 * the descriptor of the node or from the registry - never from the name of the port, because a node
	 * of another kind may number its ports differently.
	 */
	private function findTopAuxPort(array $node): string
	{
		foreach ($node['Node']['ports'] ?? [] as $port)
		{
			if (is_array($port) && ($port['type'] ?? null) === ActivityPortType::TopAux->value)
			{
				return (string)$port['id'];
			}
		}

		throw new \InvalidArgumentException(sprintf(
			"Bound node '%s' of type '%s' declares no port of type '%s': a node bound to an aux port is wired into such a port, so its descriptor has to declare one",
			$node['Name'],
			$node['Type'],
			ActivityPortType::TopAux->value,
		));
	}

	/**
	 * A port the node does not declare yet: the descriptor of the activity describes it without the
	 * wiring of this particular step, so the construct that wires a port adds the port itself.
	 *
	 * A port the node does declare has to be of the type the construct uses it as. The descriptor wins over
	 * the registry, so a port declared as something else would go into the template as it stands while the
	 * link of the construct enters it: the canvas would then read that link as another kind of wiring than
	 * the source describes - a binding through an output port, a branch through an aux one.
	 */
	private static function addPort(array &$node, string $portId, ActivityPortType $type, ?string $title = null): void
	{
		foreach ($node['Node']['ports'] ?? [] as $port)
		{
			if (!is_array($port) || ($port['id'] ?? null) !== $portId)
			{
				continue;
			}

			if (($port['type'] ?? null) !== $type->value)
			{
				// The ports of a node come from its descriptor where the source states one, and from the registry
				// of activities where it does not - so the refusal must not send the reader looking for a line of
				// the source that may not be there at all.
				throw new \InvalidArgumentException(sprintf(
					"Node '%s': port '%s' is wired as '%s', while the node declares it as '%s'. The ports of a node"
						. " are stated by its descriptor ('_node' of the step or the 'activity_descriptors' entry of"
						. " its type '%s') and, where the source states none, by the activity itself",
					$node['Name'],
					$portId,
					$type->value,
					(string)($port['type'] ?? ''),
					(string)($node['Type'] ?? ''),
				));
			}

			return;
		}

		$node['Node']['ports'][] = [
			'id' => $portId,
			'title' => $title ?? self::defaultPortTitle($portId),
			'type' => $type->value,
			'isActive' => true,
		];
	}

	/**
	 * A reference creates no node: it wires the current continuation into the standard input of an already
	 * declared one and closes the branch, so that a subgraph reachable from two triggers is written once.
	 * Whether the node exists is checked once every flow is built - a reference may point forward, and
	 * across the boundary of its own flow.
	 */
	private function buildReference(string $refId, ?NodeOutput $previous): ?NodeOutput
	{
		$this->connectAllTerminals($previous, $refId);
		$this->referencedNodeIds[$refId] = true;
		$this->unconnectedOutputs = [];

		return null;
	}

	private function assertReferencesResolved(): void
	{
		$missing = array_diff(array_keys($this->referencedNodeIds), array_column($this->activities, 'Name'));
		if (!empty($missing))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step reference '_ref' points to node(s) [%s] which no step of this agent declares",
				implode(', ', $missing),
			));
		}
	}

	/**
	 * Every link must point at ports the final nodes actually declare after descriptor overrides, and every
	 * such port must be of the type the link uses it as. The type is checked here and not where the link is
	 * made, because only a construct that writes a port of its own goes through addPort(): a chain of steps
	 * and a reference wire the standard ports of a node and add nothing. A port declared as another family
	 * would then reach the template as it stands with the link of the chain entering it, and the canvas would
	 * read that link as another kind of wiring than the source describes.
	 */
	private function assertLinkEndpointsExist(): void
	{
		$portTypesByNode = [];
		$typeByNode = [];
		foreach ($this->activities as $activity)
		{
			$name = $activity['Name'] ?? null;
			if (!is_string($name))
			{
				continue;
			}

			$portTypesByNode[$name] = [];
			$typeByNode[$name] = (string)($activity['Type'] ?? '');
			foreach ($activity['Node']['ports'] ?? [] as $port)
			{
				if (is_array($port) && is_string($port['id'] ?? null))
				{
					$portTypesByNode[$name][$port['id']] = (string)($port['type'] ?? '');
				}
			}
		}

		foreach ($this->links->getLinks() as $index => $link)
		{
			foreach (array_slice($link, 0, 2) as $side => $endpoint)
			{
				$separator = is_string($endpoint) ? strrpos($endpoint, ':') : false;
				if ($separator === false)
				{
					continue;
				}

				$nodeName = substr($endpoint, 0, $separator);
				$portId = substr($endpoint, $separator + 1);
				if (!array_key_exists($portId, $portTypesByNode[$nodeName] ?? []))
				{
					throw new \InvalidArgumentException(sprintf(
						"Template link '%s -> %s': endpoint '%s' uses port '%s' which node '%s' does not declare in Node.ports",
						(string)($link[0] ?? ''),
						(string)($link[1] ?? ''),
						$endpoint,
						$portId,
						$nodeName,
					));
				}

				$wiredAs = $this->linkPortTypes[$index][$side];
				if ($portTypesByNode[$nodeName][$portId] === $wiredAs->value)
				{
					continue;
				}

				// Worded the way addPort() words the same collision: the ports of a node come from its descriptor
				// where the source states one and from the activity itself where it does not, so the refusal must
				// not send the reader looking for a line of the source that may not be there at all.
				throw new \InvalidArgumentException(sprintf(
					"Template link '%s -> %s': endpoint '%s' wires port '%s' as '%s', while node '%s' declares it"
						. " as '%s'. The ports of a node are stated by its descriptor ('_node' of the step or the"
						. " 'activity_descriptors' entry of its type '%s') and, where the source states none, by"
						. ' the activity itself',
					(string)($link[0] ?? ''),
					(string)($link[1] ?? ''),
					$endpoint,
					$portId,
					$wiredAs->value,
					$nodeName,
					$portTypesByNode[$nodeName][$portId],
					$typeByNode[$nodeName] ?? '',
				));
			}
		}
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

	/**
	 * Node descriptor of a step: its own '_node' first, then the 'activity_descriptors' entry
	 * of the activity type. Fields missing in both are left to the registry.
	 */
	private function resolveNodeDescriptor(
		string $activityType,
		?ActivityDescriptorConfig $stepDescriptor = null,
	): ?ActivityDescriptorConfig
	{
		$typeDescriptor = $this->currentConfig->activityDescriptors[$activityType] ?? null;

		return $stepDescriptor?->mergeOver($typeDescriptor) ?? $typeDescriptor;
	}

	private function getActivityTitle(string $activityType): string
	{
		return '###' . $this->langPrefix . strtoupper($activityType) . '_TITLE###';
	}

	/**
	 * Wires one node into another and remembers what the two ports of that link are meant to be. Control flow
	 * leaves a node through an output port and enters the next one through an input port; a binding is the one
	 * link of another kind - see addStepNode() - so the two families are the default here.
	 */
	private function connectNodes(
		NodeOutput $from,
		NodeInput $to,
		ActivityPortType $fromType = ActivityPortType::Output,
		ActivityPortType $toType = ActivityPortType::Input,
	): void
	{
		$this->linkPortTypes[] = [$fromType, $toType];
		$this->links->connect($from, $to);
	}

	/**
	 * Connects all unconnected outputs from previous step to next node's standard input.
	 * If previous step was a condition, connects both true and false branch endpoints.
	 * A closed continuation with no terminals left would silently orphan the node instead.
	 */
	private function connectAllTerminals(?NodeOutput $primary, string $nextName): void
	{
		if ($primary === null && empty($this->unconnectedOutputs))
		{
			throw new \InvalidArgumentException(
				"Node '$nextName' is unreachable: the step before it closed the branch with a '_ref' reference",
			);
		}

		$to = NodeInput::standard($nextName);
		if ($primary !== null)
		{
			$this->connectNodes($primary, $to);
		}

		foreach ($this->unconnectedOutputs as $terminal)
		{
			if ($primary === null || $terminal->name !== $primary->name || $terminal->port !== $primary->port)
			{
				$this->connectNodes($terminal, $to);
			}
		}
	}

	private function buildCondition(StepConfig $step, int &$stepIndex, ?NodeOutput $previous): ?NodeOutput
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

		$condNode = $this->nodeBuilder->build(
			$activityType,
			$properties,
			$position,
			$step->id,
			$nodeTitle,
			$this->resolveNodeDescriptor($activityType, $step->nodeDescriptor),
			activityTail: $step->activityTail,
		);
		$this->addStepNode($condNode, $step, $stepIndex);
		$this->connectAllTerminals($previous, $condNode['Name']);

		$condName = $condNode['Name'];
		if ($step->isFanout)
		{
			return $this->walkPortBranches($condName, $step->fanout, $stepIndex);
		}

		$trueBranch = $this->buildBranch($step->trueBranch, NodeOutput::conditionTrue($condName), $stepIndex + 1);

		$this->layout->shiftRow($stepIndex + 1);
		$falseBranch = $this->buildBranch($step->falseBranch, NodeOutput::conditionFalse($condName), $stepIndex + 1);

		$this->layout->resetRow();
		$stepIndex = max($trueBranch->maxStepIndex, $falseBranch->maxStepIndex);

		$this->unconnectedOutputs = array_merge($trueBranch->unconnectedOutputs, $falseBranch->unconnectedOutputs);

		return $trueBranch->output;
	}

	/**
	 * Builds a multi-output activity (e.g. AiAssistantAgentComplexActivity) with arbitrary port branches:
	 * 'branches' gives every port one branch, 'fanout' gives it several.
	 */
	private function buildBranches(StepConfig $step, int &$stepIndex, ?NodeOutput $previous): ?NodeOutput
	{
		$portBranches = $step->isFanout ? $step->fanout : self::singleBranchPerPort($step->branches);
		$activityType = $this->registry->resolveActivityType($step->type);
		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);
		$properties = $this->applySetupTemplateProperties($activityType, $properties);

		$node = $this->nodeBuilder->build(
			$activityType,
			$properties,
			$position,
			$step->id,
			$nodeTitle,
			$this->resolveNodeDescriptor($activityType, $step->nodeDescriptor),
			activityTail: $step->activityTail,
		);

		$rules = $properties[ComplexActivityRules::PROPERTY] ?? null;
		$innerNames = [];
		foreach (ComplexActivityRules::actionExpressions($rules) as $expr)
		{
			$activityData = $expr['activityData'] ?? null;
			if (is_array($activityData) && !empty($activityData['Name']))
			{
				$innerNames[] = $activityData['Name'];
				// The activity is written into the wrapper as the rules state it, so every activity written
				// inside it comes along and is an activity of this template too - each of them claims its name.
				foreach (ComplexActivityRules::activityNamesWrittenBy($activityData) as $writtenName)
				{
					$this->nodeBuilder->takeName($writtenName);
				}
				if (isset($expr['auxPortId']))
				{
					$activityData['Properties'] = self::withAuxPort(
						(array)($activityData['Properties'] ?? []),
						$expr['auxPortId'],
					);
				}
				$node['Children'][] = $activityData;
			}
		}
		$this->validateInnerNamesMapping($step->id ?? '?', $innerNames, $properties);

		foreach (array_keys($portBranches) as $port)
		{
			self::addPort(
				$node,
				(string)$port,
				ActivityPortType::Output,
				$this->findPortTitleInRules($rules, (string)$port),
			);
		}

		$this->addStepNode($node, $step, $stepIndex);
		$this->connectAllTerminals($previous, $node['Name']);

		return $this->walkPortBranches($node['Name'], $portBranches, $stepIndex);
	}

	/**
	 * @param array<string, StepConfig[]> $branches
	 * @return array<string, StepConfig[][]>
	 */
	private static function singleBranchPerPort(array $branches): array
	{
		return array_map(static fn(array $branchSteps) => [$branchSteps], $branches);
	}

	/**
	 * The property that binds an activity to the aux port of the wrapper it runs inside. A template of the
	 * designer states the port twice - as 'auxPortId' of the construction wiring the activity and as this
	 * property of the copy of the activity in 'Children' - and the copy inside 'Rules' carries neither, so
	 * both paths building a wrapper derive the property from the port instead of expecting it in the source.
	 *
	 * @param array<string, mixed> $properties
	 *
	 * @return array<string, mixed>
	 */
	private static function withAuxPort(array $properties, mixed $auxPort): array
	{
		if (is_string($auxPort) && $auxPort !== '')
		{
			$properties['auxPort'] = $auxPort;
		}

		return $properties;
	}

	/**
	 * Walks the branches leaving the output ports of a node: connects 'port:i0' of every branch start and
	 * collects the tails of all branches as the unconnected outputs of the step. Branch order is the
	 * order of the links in the built template.
	 *
	 * @param array<string, StepConfig[][]> $portBranches port id => branches leaving that port
	 * @return NodeOutput|null null when every branch walked closed itself with a reference
	 */
	private function walkPortBranches(string $nodeName, array $portBranches, int &$stepIndex): ?NodeOutput
	{
		$tails = [];
		$maxStepIndex = $stepIndex;
		$primaryOutput = null;
		$branchesWalked = 0;

		foreach ($portBranches as $port => $branches)
		{
			foreach ($branches as $branchSteps)
			{
				// The first branch keeps the row of the step, every next one is shifted into a row of its own.
				if ($branchesWalked > 0)
				{
					$this->layout->shiftRow($stepIndex + 1);
				}
				$branch = $this->buildBranch($branchSteps, new NodeOutput($nodeName, $port), $stepIndex + 1);
				$tails = array_merge($tails, $branch->unconnectedOutputs);
				$maxStepIndex = max($maxStepIndex, $branch->maxStepIndex);
				$primaryOutput ??= $branch->output;
				$branchesWalked++;
			}
		}

		// One reset per shift - the same branches, counted the same way - so that a fan-out of any arity
		// leaves the cursor in the row the branching started from and not in the row shifted last.
		for ($shiftedBranch = 1; $shiftedBranch < $branchesWalked; $shiftedBranch++)
		{
			$this->layout->resetRow();
		}

		$stepIndex = $maxStepIndex;
		$this->unconnectedOutputs = $tails;

		if ($primaryOutput !== null)
		{
			return $primaryOutput;
		}

		// Nothing was walked at all: the step continues from its first declared port - never a hardcoded
		// o0, because the activity may not even have an o0 output. Otherwise every branch closed itself
		// with a reference, and nothing continues from the step.
		return $branchesWalked === 0 ? new NodeOutput($nodeName, array_key_first($portBranches) ?? 'o0') : null;
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

	/**
	 * The title the node editor would give a port it creates itself: the letter of the port family plus
	 * the one-based index of the port ('o0' -> 'O1', 'a0' -> 'T1').
	 */
	private static function defaultPortTitle(string $portId): string
	{
		if (preg_match('/^([oa])(\d+)$/', $portId, $m))
		{
			return ($m[1] === 'o' ? 'O' : 'T') . ((int)$m[2] + 1);
		}

		throw new \LogicException("Port '$portId' must match /^[oa]\\d+\$/ (validated upstream in StepConfig)");
	}

	private function findPortTitleInRules(mixed $rules, string $portId): ?string
	{
		foreach (ComplexActivityRules::outputExpressions($rules) as $expr)
		{
			if (($expr['portId'] ?? null) === $portId && isset($expr['title']))
			{
				return (string)$expr['title'];
			}
		}

		return null;
	}

	/**
	 * Builds a composite activity (ForEach, etc.) with child activities.
	 * ForEach links: previous→i0, o0→firstChild, lastChild→i1, o1→next
	 */
	private function buildComposite(StepConfig $step, int &$stepIndex, ?NodeOutput $previous): NodeOutput
	{
		$activityType = $this->registry->resolveActivityType($step->type);
		$position = $this->layout->calculatePosition($stepIndex);

		$properties = $step->props;
		$properties['Title'] = $properties['Title'] ?? $this->getActivityTitle($activityType);
		$nodeTitle = $this->takeNodeTitle($properties);

		$compositeNode = $this->nodeBuilder->build(
			$activityType,
			$properties,
			$position,
			$step->id,
			$nodeTitle,
			$this->resolveNodeDescriptor($activityType, $step->nodeDescriptor),
			activityTail: $step->activityTail,
		);
		$this->addStepNode($compositeNode, $step, $stepIndex);
		$this->connectAllTerminals($previous, $compositeNode['Name']);

		$compositeName = $compositeNode['Name'];

		$childBranch = $this->buildBranch($step->childSteps, NodeOutput::sequential($compositeName), $stepIndex + 1);

		// Unconnected outputs of the body return to the composite return port (i1). A body that closed
		// itself with a reference leaves none of them, and then nothing returns into the loop.
		$returnInput = NodeInput::compositeReturn($compositeName);
		foreach ($childBranch->unconnectedOutputs as $terminal)
		{
			$this->connectNodes($terminal, $returnInput);
		}

		$this->unconnectedOutputs = [];
		$stepIndex = $childBranch->maxStepIndex;

		if ($activityType === ActivityRegistry::FOREACH_ACTIVITY_TYPE)
		{
			$this->layout->reserveCompositeLoopback($position);
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

		// A branch closed by a reference has no continuation and no terminals: it takes no part in the
		// fan-in of the next step.
		$unconnected = match (true)
		{
			!empty($this->unconnectedOutputs) => $this->unconnectedOutputs,
			$current !== null => [$current],
			default => [],
		};

		return new BranchResult(
			output: $current,
			unconnectedOutputs: $unconnected,
			maxStepIndex: $branchStepIndex - 1,
		);
	}

	private function buildComplexWrapperStep(StepConfig $step, int &$stepIndex, ?NodeOutput $previous): NodeOutput
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

		// Whether the step builds a wrapper is stated by the step; whether a wrapper can be built at all is known
		// from the activity alone. A type this environment does not resolve as a complex wrapper leaves the build
		// no way to write one - the aux port, the exit port and the height of the node all come from the activity
		// - and a node descriptor is no answer here: it states how the node looks (TPL-03), not what it wraps.
		if (!$this->registry->isComplexWrapper($outerType))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s'%s names '%s' as the activity it runs inside itself, while '%s' does not resolve as a"
					. ' complex wrapper in this environment: the build would write a plain node instead and the'
					. " delivered agent would lose that activity. Install the module owning '%s', or write the"
					. " 'Rules' of the wrapper into the step itself, the activity inside them included",
				$step->type,
				$step->id === null ? '' : " ('{$step->id}')",
				$innerType,
				$outerType,
				$outerType,
			));
		}

		$auxPort = $this->registry->getAuxPort($outerType);
		$position = $this->layout->calculatePosition($stepIndex);
		$outerTitle = $this->getActivityTitle($outerType);

		$innerProps = $step->props;
		$innerProps['Title'] = $innerProps['Title'] ?? $outerTitle;
		$innerProps['EditorComment'] = '';
		$innerProps = self::withAuxPort($innerProps, $auxPort);

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

		$descriptor = $this->resolveNodeDescriptor($outerType, $step->nodeDescriptor);
		// The return properties of the inner activity come from the registry right here, so this is the one
		// path whose node depends on the inner type resolving in this environment.
		$node = $this->nodeBuilder->build(
			$outerType,
			$outerProperties,
			$position,
			$step->id,
			descriptor: $descriptor,
			innerActivityType: $innerType,
			activityTail: $step->activityTail,
		);
		// Registry ports and dimensions describe the outer activity alone, so the wrapper adds its own
		// exit port and height. A descriptor already carries the whole node and needs no amending.
		if ($descriptor?->ports === null)
		{
			$node['Node']['ports'] = $this->buildComplexWrapperPorts($node['Node']['ports'] ?? []);
		}
		if ($descriptor?->dimensions === null)
		{
			$node['Node']['dimensions']['height'] = 291;
		}
		$this->nodeBuilder->takeName($innerId);
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

		$this->addStepNode($node, $step, $stepIndex);
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
				// A property holding several values says so in its description, and the field built from it
				// in the designer keeps every value only while the template says so too.
				'Multiple' => (bool)($def['MULTIPLE'] ?? false),
				'Required' => false,
				'Options' => null,
				'Settings' => null,
				'Default' => null,
			];
		}

		return $result;
	}

	/**
	 * The wizard of the setup activity is rebuilt from the constants of the config for every shape the step
	 * of that activity can take - a plain step, 'branches' or 'fanout' all end up as the same node, and the
	 * reverse drops 'user' and 'blocks' from the source counting on the build to restore them.
	 *
	 * @param array<string, mixed> $properties
	 *
	 * @return array<string, mixed>
	 */
	private function applySetupTemplateProperties(string $activityType, array $properties): array
	{
		return $activityType === ActivityRegistry::SETUP_ACTIVITY_TYPE
			? $this->buildSetupTemplateProperties($properties)
			: $properties;
	}

	private function buildSetupTemplateProperties(array $existingProps): array
	{
		$this->hasSetupTemplateActivity = true;

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

			// The wizard states the constant in full, the hint and the settings of the field type included: the
			// block of the wizard lives inside TEMPLATE, so what is dropped here moves the revision of the agent.
			// Whether the element is required and what it is filled with are asked of the constant at the wizard
			// level: the two places of the template disagree in six of the shipped agents, and it is the wizard
			// that decides what the person setting the agent up has to fill in.
			$currentItems->add(new SetupTemplateActivity\Constant(
				id: $key,
				name: $this->wrapLangKey($constant->label),
				constantType: $constant->type,
				description: $this->wrapOptionalLangKey($constant->description),
				multiple: $constant->multiple,
				required: $constant->isRequiredInWizard(),
				options: $this->wrapOptions($constant->options ?? []),
				settings: $constant->settings ?? [],
				default: $constant->getDefaultInWizard(),
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

	/**
	 * A lang key as the template carries it. An empty key is no key at all: '######' would be a key of its own,
	 * and a template stating nothing there could never be reversed into a source that builds back into it.
	 */
	private function wrapLangKey(string $key): string
	{
		return $key === '' ? '' : '###' . $key . '###';
	}

	/** A lang key the source may leave out: the template carries an empty string where it does. */
	private function wrapOptionalLangKey(?string $key): string
	{
		return $key === null ? '' : $this->wrapLangKey($key);
	}

	/**
	 * @param array<string|int, string> $options value of an option => lang key of its label
	 *
	 * @return array<string, string>
	 */
	private function wrapOptions(array $options): array
	{
		$wrapped = [];

		foreach ($options as $value => $labelKey)
		{
			$wrapped[(string)$value] = $this->wrapLangKey($labelKey);
		}

		return $wrapped;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityNodeBuilder;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\TemplateBuilder;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;

/**
 * Reverses a generated template.json back into a template.source.json structure.
 *
 * Mirror of TemplateBuilder. The result is semantically equivalent to the original
 * source, but exact byte equivalence is not guaranteed (key order, omitted defaults).
 */
final class TemplateReverser
{
	private const ROOT_PATH_PROPERTIES = 'Properties';
	private const ROOT_PATH_CHILDREN = 'Children';

	private const TRIGGER_FLOW_NAME_MAP = [
		'AiAgentStartTrigger' => 'setup',
		'ScheduledTrigger' => 'scheduled',
		'ImBotNewMessageTrigger' => 'bot_reply',
		'StartWorkTimeTrigger' => 'workday_start',
		'StopWorkTimeTrigger' => 'workday_end',
	];

	/** @var array<string, array> indexed by Name */
	private array $activities = [];

	private LinkGraph $graph;

	/** @var array<string, true> activity names referenced via {=Name:...} or in conditions */
	private array $referencedIds = [];

	/** @var array<string, true> already visited during walk to avoid cycles */
	private array $visited = [];

	/** Mirror of TemplateBuilder::$langPrefix — used to detect auto-generated Title values. */
	private string $langPrefix = '';

	public function __construct(
		private readonly ActivityRegistry $registry,
	)
	{
	}

	public function reverse(array $template, string $agentName): array
	{
		$root = $template['TEMPLATE'][0] ?? null;
		if (!is_array($root))
		{
			throw new \InvalidArgumentException('template.TEMPLATE[0] missing');
		}

		$children = $root[self::ROOT_PATH_CHILDREN] ?? [];
		$links = $root[self::ROOT_PATH_PROPERTIES]['Links'] ?? [];

		$this->activities = [];
		foreach ($children as $activity)
		{
			if (isset($activity['Name']))
			{
				$this->activities[$activity['Name']] = $activity;
			}
		}
		$knownNodes = array_fill_keys(array_keys($this->activities), true);
		$this->graph = new LinkGraph($links, $knownNodes);
		$this->referencedIds = $this->collectReferencedIds();
		$this->visited = [];
		$this->langPrefix = strtoupper(str_replace(' ', '_', $agentName)) . '_';

		$wizard = $this->extractWizardMeta();

		$result = [
			'name' => $agentName,
			'title' => $this->unwrapLang($template['NAME'] ?? ''),
			'description' => $this->unwrapLang($template['DESCRIPTION'] ?? ''),
		];

		if ($wizard?->title !== null)
		{
			$result['wizard_title'] = $wizard->title;
		}
		if ($wizard?->description !== null)
		{
			$result['wizard_description'] = $wizard->description;
		}

		$result['constants'] = $this->reverseConstants($template['CONSTANTS'] ?? [], $wizard);
		$result['flows'] = $this->reverseFlows();

		return $result;
	}

	/**
	 * Verifies that re-building the reversed source produces the same links between known nodes
	 * as the original template. Discrepancies indicate reverse drift.
	 */
	public function verifyRoundTrip(array $originalTemplate, array $reversedSource): RoundTripReport
	{
		$config = AgentConfig::fromArray($reversedSource);
		$builder = new TemplateBuilder($this->registry, new ActivityNodeBuilder($this->registry));
		$rebuilt = $builder->build($config);

		$known = [];
		foreach ($originalTemplate['TEMPLATE'][0]['Children'] ?? [] as $a)
		{
			if (isset($a['Name']) && is_string($a['Name']))
			{
				$known[$a['Name']] = true;
			}
		}

		$originalLinks = $this->extractKnownLinks(
			$originalTemplate['TEMPLATE'][0]['Properties']['Links'] ?? [],
			$known,
		);
		$rebuiltLinks = $this->extractKnownLinks($rebuilt['TEMPLATE'][0]['Properties']['Links'] ?? [], $known);

		return new RoundTripReport(
			lost: array_values(array_diff($originalLinks, $rebuiltLinks)),
			extra: array_values(array_diff($rebuiltLinks, $originalLinks)),
		);
	}

	/**
	 * @param list<array> $links
	 * @param array<string, true> $known
	 *
	 * @return list<string>
	 */
	private function extractKnownLinks(array $links, array $known): array
	{
		$result = [];
		foreach ($links as $link)
		{
			if (!is_array($link) || !isset($link[0], $link[1]))
			{
				continue;
			}
			$from = (string)$link[0];
			$to = (string)$link[1];
			$fromName = strstr($from, ':', true);
			$toName = strstr($to, ':', true);
			if ($fromName === false || $toName === false)
			{
				continue;
			}
			if (!isset($known[$fromName], $known[$toName]))
			{
				continue;
			}
			$result[] = $from . ' -> ' . $to;
		}
		sort($result);

		return $result;
	}

	private function reverseConstants(array $rawConstants, ?WizardMeta $wizard): array
	{
		$constants = [];

		foreach ($rawConstants as $key => $data)
		{
			$entry = [
				'label' => $this->unwrapLang($data['Name'] ?? ''),
				'type' => $data['Type'] ?? 'string',
			];

			if (!empty($data['Multiple']))
			{
				$entry['multiple'] = true;
			}
			if (!empty($data['Required']))
			{
				$entry['required'] = true;
			}

			if ($wizard !== null && !isset($wizard->constantKeys[(string)$key]))
			{
				$entry['show_in_wizard'] = false;
			}

			$perConstant = $wizard?->perConstantWizard[(string)$key] ?? null;
			if ($perConstant !== null)
			{
				$entry['wizard_title'] = $perConstant->title;
				if ($perConstant->description !== null)
				{
					$entry['wizard_description'] = $perConstant->description;
				}
			}

			if (!empty($data['Options']) && is_array($data['Options']))
			{
				$options = [];
				foreach ($data['Options'] as $value => $labelKey)
				{
					$options[(string)$value] = $this->unwrapLang((string)$labelKey);
				}
				$entry['options'] = $options;
			}

			$default = $data['Default'] ?? '';
			if ($default !== '')
			{
				$entry['default'] = $default;
			}

			$constants[(string)$key] = $entry;
		}

		return $constants;
	}

	/**
	 * Extracts wizard metadata from the first SetupTemplateActivity.
	 *
	 * `buildSetupTemplateProperties` emits one base block (global wizardTitle/wizardDescription
	 * + visible constants without per-constant wizard) and starts a new block whenever it hits
	 * a constant with its own wizardTitle. Reverse mirrors that: title/description of the FIRST
	 * block become global wizard meta; title/description of any subsequent block attach to the
	 * FIRST constant of that block (mirrors builder, where only the constant that declared
	 * wizardTitle triggered the new block).
	 *
	 * Returns null when no SetupTemplateActivity is present in the template — callers use this
	 * to distinguish "no wizard at all" from "wizard exists but has no items".
	 */
	private function extractWizardMeta(): ?WizardMeta
	{
		$setup = $this->findActivityByType();
		if ($setup === null)
		{
			return null;
		}
		$blocks = $setup['Properties']['blocks'] ?? null;
		if (!is_array($blocks))
		{
			return null;
		}

		$wizardTitle = null;
		$wizardDescription = null;
		$constantKeys = [];
		$perConstant = [];
		$isFirstTitledBlock = true;

		foreach ($blocks as $block)
		{
			if (!is_array($block) || empty($block['items']))
			{
				continue;
			}

			$parsed = $this->parseSetupBlock($block);
			foreach ($parsed->constantIds as $cid)
			{
				$constantKeys[$cid] = true;
			}

			if ($parsed->title === null)
			{
				continue;
			}

			if ($isFirstTitledBlock)
			{
				$wizardTitle = $parsed->title;
				$wizardDescription = $parsed->description;
				$isFirstTitledBlock = false;
				continue;
			}

			// Builder opens a new block only on the constant whose wizardTitle != null.
			// Only that constant owns the title in source; the rest of the block trails
			// from constants without wizardTitle. Reverse must mirror that — otherwise
			// the next forward build splits one block into N (round-trip not idempotent).
			$firstCid = $parsed->constantIds[0] ?? null;
			if ($firstCid !== null)
			{
				$perConstant[$firstCid] = new PerConstantWizard($parsed->title, $parsed->description);
			}
		}

		return new WizardMeta($wizardTitle, $wizardDescription, $constantKeys, $perConstant);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findActivityByType(): ?array
	{
		foreach ($this->activities as $activity)
		{
			if (($activity['Type'] ?? null) === ActivityRegistry::SETUP_ACTIVITY_TYPE)
			{
				return $activity;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private function parseSetupBlock(array $block): ParsedSetupBlock
	{
		$title = null;
		$description = null;
		$constantIds = [];
		foreach ($block['items'] as $item)
		{
			$itemType = ItemType::tryFrom((string)($item['itemType'] ?? ''));
			if ($itemType === ItemType::Title)
			{
				$title = $this->unwrapLang((string)($item['text'] ?? ''));
			}
			elseif ($itemType === ItemType::Description)
			{
				$description = $this->unwrapLang((string)($item['text'] ?? ''));
			}
			elseif ($itemType === ItemType::Constant && isset($item['id']))
			{
				$constantIds[] = (string)$item['id'];
			}
		}

		return new ParsedSetupBlock($title, $description, $constantIds);
	}

	private function reverseFlows(): array
	{
		$flows = [];
		$usedNames = [];

		foreach ($this->activities as $activity)
		{
			if (!$this->registry->isTrigger($activity['Type'] ?? ''))
			{
				continue;
			}

			$flowName = $this->makeFlowName((string)$activity['Type'], $usedNames);
			$flows[$flowName] = $this->buildFlow($activity);
		}

		return $flows;
	}

	private function makeFlowName(string $triggerType, array &$used): string
	{
		$base = self::TRIGGER_FLOW_NAME_MAP[$triggerType] ?? $this->snakeCase($triggerType);
		$name = $base;
		$i = 1;
		while (isset($used[$name]))
		{
			$i++;
			$name = $base . '_' . $i;
		}
		$used[$name] = true;

		return $name;
	}

	private function snakeCase(string $type): string
	{
		$type = preg_replace('/(Trigger|Activity)$/', '', $type) ?? $type;
		$type = preg_replace('/([a-z])([A-Z])/', '$1_$2', $type) ?? $type;

		return strtolower($type);
	}

	private function buildFlow(array $trigger): array
	{
		$flow = [
			'trigger' => (string)$trigger['Type'],
		];

		$triggerProps = $this->cleanActivityProps($trigger);
		$id = $this->idIfKept($trigger['Name']);
		if ($id !== null)
		{
			$triggerProps = ['_id' => $id] + $triggerProps;
		}
		if ($triggerProps !== [])
		{
			$flow['trigger_props'] = $triggerProps;
		}

		// Each flow is an independent graph traversal. Resetting visited prevents one flow
		// from truncating another when both reach a shared (e.g. accidentally-shared) node.
		$this->visited = [];
		$this->visited[$trigger['Name']] = true;
		$flow['steps'] = $this->walkChain($trigger['Name'], 'o0', null);

		return $flow;
	}

	/**
	 * Walks the chain from $fromName:$fromPort, building step entries until a stop node is reached.
	 *
	 * @param string|null $stopAt stop when reaching this node (used as merge-point in conditions/branches)
	 * @param string|null $loopbackTarget composite container whose i1 loopback marks "end of body";
	 *                                     when set, also breaks the chain if $cur equals the container itself
	 */
	private function walkChain(
		string $fromName,
		string $fromPort,
		?string $stopAt,
		?string $loopbackTarget = null,
	): array
	{
		$steps = [];
		$cur = $this->graph->follow($fromName, $fromPort);

		while ($cur !== null && $cur !== $stopAt)
		{
			if ($loopbackTarget !== null && $cur === $loopbackTarget)
			{
				break; // composite body wraps directly back to its container
			}
			if (isset($this->visited[$cur]))
			{
				break;
			}
			if (!isset($this->activities[$cur]))
			{
				break;
			}

			$activity = $this->activities[$cur];
			$type = (string)($activity['Type'] ?? '');

			if ($type === ActivityRegistry::CONDITION_ACTIVITY_TYPE)
			{
				$this->visited[$cur] = true;
				$mergePoint = $this->findMergePoint($cur);
				$steps[] = $this->buildConditionStep($activity, $mergePoint);
				$cur = $mergePoint;
				continue;
			}

			if ($this->registry->isComposite($type))
			{
				$this->visited[$cur] = true;
				$steps[] = $this->buildCompositeStep($activity);
				$cur = $this->graph->follow($cur, 'o1');
				continue;
			}

			$outPorts = $this->graph->outgoingPorts($cur);
			// Any non-default port topology — multiple ports OR a single non-o0 port — is reified as branches.
			$nonStandard = !empty($outPorts) && !in_array('o0', $outPorts, true);
			$this->visited[$cur] = true;
			if (count($outPorts) >= 2 || $nonStandard)
			{
				// For ≥2 ports there can be a downstream merge; for a single port the branch absorbs the rest of the chain.
				$mergePoint = count($outPorts) >= 2 ? $this->findCommonMerge($cur, $outPorts) : null;
				$steps[] = $this->buildBranchesStep($activity, $outPorts, $mergePoint);
				$cur = $mergePoint;
				continue;
			}

			$steps[] = $this->buildSimpleStep($activity);

			// In a composite body, the tail step may loop back to the container via o0 → container:i1
			if ($loopbackTarget !== null && $this->loopsBackTo($cur, $loopbackTarget))
			{
				break;
			}
			$cur = $this->graph->follow($cur, 'o0');
		}

		return $steps;
	}

	/**
	 * Generic merge-point search for a multi-output activity with arbitrary ports.
	 * Returns the closest node reachable from ALL ports and having inDegree(i0) ≥ count(ports).
	 *
	 * @param list<string> $ports
	 */
	private function findCommonMerge(string $name, array $ports): ?string
	{
		$reaches = [];
		foreach ($ports as $port)
		{
			$start = $this->graph->follow($name, $port);
			$reaches[$port] = $start !== null ? $this->reachUntilBoundary($start) : [];
		}

		$common = null;
		foreach ($reaches as $r)
		{
			$common = $common === null ? $r : array_intersect_key($common, $r);
			if (empty($common))
			{
				return null;
			}
		}
		if ($common === null)
		{
			return null;
		}

		$minPorts = count($ports);
		$merge = null;
		$bestDist = PHP_INT_MAX;

		foreach (array_keys($common) as $node)
		{
			if ($this->graph->inDegree($node, 'i0') < $minPorts)
			{
				continue;
			}
			$dist = 0;
			foreach ($reaches as $r)
			{
				$dist += $r[$node];
			}
			if ($dist < $bestDist)
			{
				$bestDist = $dist;
				$merge = $node;
			}
		}

		return $merge;
	}

	/**
	 * @param list<string> $ports
	 */
	private function buildBranchesStep(array $activity, array $ports, ?string $mergePoint): array
	{
		$type = (string)$activity['Type'];
		$props = $this->cleanActivityProps($activity);

		$branches = [];
		foreach ($ports as $port)
		{
			$branches[$port] = $this->walkChain($activity['Name'], $port, $mergePoint);
		}

		$body = $props;
		$id = $this->idIfKept($activity['Name']);
		if ($id !== null)
		{
			$body = ['_id' => $id] + $body;
		}
		$body['branches'] = $branches;

		return [$type => $body];
	}

	private function findMergePoint(string $condName): ?string
	{
		$trueStart = $this->graph->follow($condName, 'o0');
		$falseStart = $this->graph->follow($condName, 'o1');

		// Bounded BFS: stop traversal through nodes with inDegree >= 2 (potential merge boundaries).
		// This prevents nested conditions' merge-points from being misidentified as the parent's merge.
		$trueReach = $trueStart !== null ? $this->reachUntilBoundary($trueStart) : [];
		$falseReach = $falseStart !== null ? $this->reachUntilBoundary($falseStart) : [];

		$merge = null;
		$bestDist = PHP_INT_MAX;

		foreach ($trueReach as $node => $dT)
		{
			if (!isset($falseReach[$node]))
			{
				continue;
			}
			$inDeg = $this->graph->inDegree($node, 'i0');
			if ($inDeg < 2)
			{
				continue;
			}
			$dist = $dT + $falseReach[$node];
			if ($dist < $bestDist)
			{
				$bestDist = $dist;
				$merge = $node;
			}
		}

		return $merge;
	}

	/**
	 * BFS from $startName that does not traverse THROUGH nodes whose inDegree(i0) >= 2.
	 * Boundary nodes themselves are included with their distance; their descendants are not.
	 *
	 * @return array<string, int>
	 */
	private function reachUntilBoundary(string $startName): array
	{
		$distances = [];
		$queue = [[$startName, 0]];
		$head = 0;

		while ($head < count($queue))
		{
			[$current, $dist] = $queue[$head++];
			if (isset($distances[$current]))
			{
				continue;
			}
			$distances[$current] = $dist;

			// Boundary: do not expand THROUGH inDegree>=2 nodes (except the entry point itself).
			if ($current !== $startName && $this->graph->inDegree($current, 'i0') >= 2)
			{
				continue;
			}

			// Use the node's actual outgoing port list so multi-branch activities (≥3 ports)
			// don't get truncated to o0/o1 — findCommonMerge depends on full reachability.
			foreach ($this->graph->outgoingPorts($current) as $outPort)
			{
				foreach ($this->graph->allOutgoing($current, $outPort) as $next)
				{
					if ($next['port'] === 'i1' && $next['name'] === $current)
					{
						continue;
					}
					if (!isset($distances[$next['name']]))
					{
						$queue[] = [$next['name'], $dist + 1];
					}
				}
			}
		}

		return $distances;
	}

	private function buildConditionStep(array $activity, ?string $mergePoint): array
	{
		$conditions = [];
		$rawConditions = $activity['Properties']['mixedcondition'] ?? [];
		if (!is_array($rawConditions))
		{
			$rawConditions = [];
		}
		foreach ($rawConditions as $c)
		{
			$entry = [
				'object' => $c['object'] ?? '',
				'field' => $c['field'] ?? '',
				'operator' => $c['operator'] ?? '',
				'value' => $c['value'] ?? '',
			];
			$joiner = $c['joiner'] ?? 0;
			if ($joiner)
			{
				$entry['joiner'] = (int)$joiner;
			}
			$conditions[] = $entry;
		}

		$trueBranch = $this->walkChain($activity['Name'], 'o0', $mergePoint);
		$falseBranch = $this->walkChain($activity['Name'], 'o1', $mergePoint);

		// Preserve user-set Title (and any other custom props) — cleanActivityProps drops
		// only the auto-generated title pattern and engine-managed fields.
		$props = $this->cleanActivityProps($activity);
		unset($props['mixedcondition']);
		$body = $props + [
				'conditions' => $conditions,
				'true' => $trueBranch,
				'false' => $falseBranch,
			];
		$id = $this->idIfKept($activity['Name']);
		if ($id !== null)
		{
			$body = ['_id' => $id] + $body;
		}

		return ['Condition' => $body];
	}

	private function buildCompositeStep(array $activity): array
	{
		$type = (string)$activity['Type'];
		$configType = $type === ActivityRegistry::FOREACH_ACTIVITY_TYPE ? 'ForEach' : $type;

		$props = $this->cleanActivityProps($activity);

		// ForEach reverse: collapse Object + Variable back into Variable: "{=Object:Variable}"
		if (
			$type === ActivityRegistry::FOREACH_ACTIVITY_TYPE
			&& isset($props['Object'])
			&& isset($props['Variable'])
		)
		{
			$props['Variable'] = '{=' . $props['Object'] . ':' . $props['Variable'] . '}';
			unset($props['Object']);
		}

		// Walk the composite body: starts at composite:o0, ends either when a tail wraps back
		// to composite:i1 or when we step directly back into composite itself (empty body case).
		$childSteps = $this->walkChain($activity['Name'], 'o0', null, $activity['Name']);

		$body = $props;
		$id = $this->idIfKept($activity['Name']);
		if ($id !== null)
		{
			$body = ['_id' => $id] + $body;
		}
		$body['steps'] = $childSteps;

		return [$configType => $body];
	}

	private function loopsBackTo(string $name, string $composite): bool
	{
		foreach ($this->graph->allOutgoing($name, 'o0') as $target)
		{
			if ($target['name'] === $composite && $target['port'] === 'i1')
			{
				return true;
			}
		}

		return false;
	}

	private function buildSimpleStep(array $activity): string|array
	{
		$type = (string)$activity['Type'];
		// cleanActivityProps already strips engine-rebuilt fields (user/blocks for setup,
		// auto-generated Title) and keeps custom values (e.g. user-authored Title).
		$props = $this->cleanActivityProps($activity);

		$id = $this->idIfKept($activity['Name']);

		if (empty($props) && $id === null)
		{
			return $type;
		}

		$body = $props;
		if ($id !== null)
		{
			$body = ['_id' => $id] + $body;
		}

		return [$type => $body];
	}

	private function cleanActivityProps(array $activity): array
	{
		$props = $activity['Properties'] ?? [];
		if (!is_array($props))
		{
			return [];
		}

		// Title is rebuilt by the generator from langPrefix iff the user did not specify one.
		// Drop only the auto-generated pattern ###<LANG_PREFIX><TYPE>_TITLE###; keep custom titles.
		if (
			isset($props['Title'])
			&& $this->isAutoGeneratedTitle(
				(string)$props['Title'],
				(string)($activity['Type'] ?? ''),
			)
		)
		{
			unset($props['Title']);
		}

		// Empty UI-only fields (e.g. blank designer comments) just clutter the source.
		if (($props['EditorComment'] ?? null) === '')
		{
			unset($props['EditorComment']);
		}

		if (($activity['Type'] ?? '') === ActivityRegistry::SETUP_ACTIVITY_TYPE)
		{
			// user + blocks are rebuilt by buildSetupTemplateProperties from constants + wizard meta.
			unset($props['user'], $props['blocks']);
		}

		$canvasTitle = $activity['Node']['node']['title'] ?? null;
		$propTitle = $props['Title'] ?? null;
		if (is_string($canvasTitle) && $canvasTitle !== '' && $canvasTitle !== $propTitle)
		{
			$props['NodeTitle'] = $canvasTitle;
		}

		return $props;
	}

	private function idIfKept(string $name): ?string
	{
		if ($this->isAutoGeneratedId($name))
		{
			if (!isset($this->referencedIds[$name]))
			{
				return null;
			}
		}

		return $name;
	}

	private function isAutoGeneratedId(string $name): bool
	{
		return (bool)preg_match('/^A0{4}_0{4}_0{4}_\d{4}$/', $name);
	}

	private function isAutoGeneratedTitle(string $title, string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		$expected = '###' . $this->langPrefix . strtoupper($activityType) . '_TITLE###';

		return $title === $expected;
	}

	/** @return array<string, true> */
	private function collectReferencedIds(): array
	{
		$refs = [];

		$walker = function($value) use (&$walker, &$refs): void {
			$inlinePattern = '/\{=(' . StepConfig::ID_PATTERN_BODY . '):/';
			if (is_string($value))
			{
				if (preg_match_all($inlinePattern, $value, $m))
				{
					foreach ($m[1] as $id)
					{
						$refs[$id] = true;
					}
				}

				return;
			}
			if (is_array($value))
			{
				if (
					isset($value['object']) && is_string($value['object'])
					&& preg_match(
						StepConfig::ID_PATTERN,
						$value['object'],
					)
				)
				{
					$refs[$value['object']] = true;
				}
				foreach ($value as $v)
				{
					$walker($v);
				}
			}
		};

		foreach ($this->activities as $activity)
		{
			$walker($activity['Properties'] ?? []);
		}

		return $refs;
	}

	private function unwrapLang(string $value): string
	{
		if (preg_match('/^###(.+)###$/', $value, $m))
		{
			return $m[1];
		}

		return $value;
	}
}

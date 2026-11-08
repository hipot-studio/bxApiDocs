<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;

/**
 * Shared visibility predicate for AI agent block catalogs. The REST catalog (AgentBlockCatalogService) and the
 * Marta catalog (BlockDescriptionService) must hide the same blocks, or an agent is offered a block it cannot build.
 *
 * Hidden from both: legacy composite containers (own children/branches, no NODE_TYPE - the draft converter would
 * silently lose their structure), converter-unbuildable topologies (SERVICE, TOOL) and the presentational pool
 * (POOL, a container nobody authors from an agent). COMPLEX and OPERATORS nodes are buildable and stay visible;
 * the staged rollout of COMPLEX is handled by environment guards (EXCLUDED, the node-readiness option, switchnode
 * dev-ENV registration), never by re-adding it here.
 *
 * Visible to REST only: FRAME (a presentational overlay Marta stays isolated from), plus the storage and
 * reverse-visible CLASSes, which reverse validation must read back verbatim so a system AI agent's own
 * template.get output survives template.validate. Every other node type resolves identically on both predicates.
 *
 * Hiding a node from the catalogs and letting it back in on the reverse path are two separate questions: the pool
 * is hidden from both catalogs and still reverse-visible, because a user may draw one into a template an agent
 * later reads.
 */
final class ActivityVisibilityFilter
{
	/**
	 * Legacy composite containers that the agent draft converter cannot build
	 * as simple i0/o0 nodes.
	 *
	 * @see isNodeCompatible(), isNodeCompatibleFromRaw()
	 */
	public const LEGACY_COMPOSITE_CLASS_BLACKLIST = [
		// Original 5 - root/branching containers
		'IfElseActivity',
		'ParallelActivity',
		'SequenceActivity',
		'StateMachineWorkflowActivity',
		'SequentialWorkflowActivity',
		// Multi-child event listeners (extends CBPCompositeActivity, NODE_TYPE=null,
		// TYPE=activity, no EXCLUDED)
		'RequestInformationActivity', // CBPRequestInformationActivity - collects $arActivities for approval branches
		'ListenActivity',             // CBPListenActivity - waits for one of N event branches
		'StateActivity',              // CBPStateActivity - sub-state container in StateMachineWorkflow
		// CBPSequenceActivity descendants with NODE_TYPE=null (no EXCLUDED)
		// EmptyBlockActivity is intentionally NOT here: it carries NODE_TYPE=FRAME and is gated by the
		// NODE_TYPE axis (visible to REST, hidden from Marta), not by this legacy-class blacklist.
		'StateInitializationActivity',   // CBPStateInitializationActivity - entry-point sequence for state
		'StateFinalizationActivity',     // CBPStateFinalizationActivity - exit-point sequence for state
		'EventDrivenActivity',           // CBPEventDrivenActivity - event-driven sequence with $arActivities presets
	];

	/**
	 * NODE_TYPE values no agent catalog offers. Rejected on both predicates (REST and Marta).
	 *
	 * SERVICE and TOOL map to topologies the agent draft converter cannot build. POOL is buildable and
	 * is withheld for the opposite reason: it is a presentational container the user draws to group a
	 * diagram by participant, it carries no ports and drives nothing an agent could author. Its rollout
	 * flag withholds nothing here - EXCLUDED hides the palette card only while the flag is off, and the
	 * pool stays out of both catalogs once the flag is on.
	 *
	 * Authoring a pool is what this list refuses, never reading one back: the forward converter emits a
	 * pool the user drew as an ordinary block, so template.get hands it to the agent and template.validate
	 * must accept it again. That read-back is granted by {@see self::REVERSE_VISIBLE_CLASS_WHITELIST},
	 * which lifts this barrier on the reverse path alone and leaves the catalog predicate untouched.
	 *
	 * COMPLEX is intentionally absent: the rule-engine ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand})
	 * now assembles the complex sub-graph, so complex nodes are buildable and visible. Their staged
	 * rollout is enforced by environment guards (EXCLUDED option, node-readiness option, switchnode
	 * dev-ENV registration), not by this list.
	 *
	 * FRAME is intentionally absent too: presentational like the pool, but offered to REST and withheld
	 * from Marta alone - see {@see self::MARTA_HIDDEN_NODE_TYPES}.
	 */
	private const ALWAYS_HIDDEN_NODE_TYPES = [
		ActivityNodeType::SERVICE->value,
		ActivityNodeType::TOOL->value,
		ActivityNodeType::POOL->value,
	];

	/**
	 * NODE_TYPE values visible to the external REST agent (typed predicate) but hidden from Marta (raw
	 * predicate). FRAME (EmptyBlockActivity, preset FRAME) is the frame overlay: REST agents set its
	 * logical membership and styling, while Marta stays isolated from it.
	 */
	private const MARTA_HIDDEN_NODE_TYPES = [
		ActivityNodeType::FRAME->value,
	];

	/**
	 * Storage-subsystem CLASSes the external REST agent must read back verbatim, so its own template.get output
	 * survives template.validate. They declare NODE_TYPE=SERVICE, so the whitelist inverts
	 * {@see self::ALWAYS_HIDDEN_NODE_TYPES} point-wise for them. Matched by CLASS: the lowercase catalog type-code
	 * is not available inside the predicates.
	 *
	 * EXCLUDED still wins (it is checked before the predicate), so setuptemplateactivity stays gated by
	 * setExcluded(AiAgentSectionFlag).
	 *
	 * @see isNodeCompatible(), isNodeCompatibleFromRaw()
	 */
	public const STORAGE_REST_VISIBLE_CLASS_WHITELIST = [
		'CreateStorageNode',
		'WriteDataStorageActivity',
		'ReadDataStorageActivity',
		'DeleteDataStorageActivity',
		'SetupTemplateActivity',
	];

	/**
	 * CLASSes a system AI agent's template.get emits and reverse validation must read back verbatim: the
	 * external-module nodes a system agent authors, plus the pool, which no agent authors but any user may
	 * draw into a template an agent then reads. An entry lifts the topology barrier of
	 * {@see self::ALWAYS_HIDDEN_NODE_TYPES} (SERVICE, TOOL and POOL alike) outright, and is a precondition -
	 * not a licence on its own - for lifting the EXCLUDED barrier
	 * checked before the predicate ({@see self::bypassesExcludedFromRaw()}): that one also demands the block type
	 * be present in the published baseline. Which of the two barriers a given CLASS actually hits is decided by
	 * its owner module and can change there, so entries are not grouped by barrier here.
	 *
	 * Unlike the storage whitelist this list is reverse-only - the typed catalog.list predicate ignores it, so
	 * these nodes stay out of the palette the agent is offered to author.
	 *
	 * @see isNodeCompatibleFromRaw(), bypassesExcludedFromRaw()
	 */
	public const REVERSE_VISIBLE_CLASS_WHITELIST = [
		'RagKnowledgeBaseNode',
		'AiAssistantInternalMcpNode',
		'BIConnectorDashboardResultActivity',
		'CrmClientSummaryActivity',
		'AiProcessingActivity',
		// Drawn by the user, never by an agent: read back so a template carrying a pool survives
		// template.get -> template.validate. The catalog predicate ignores this list, so the pool
		// stays out of the palette the agent is offered.
		'PoolNode',
	];

	/**
	 * Visibility predicate for the external REST agent, working on typed ActivityDescription objects
	 * (used by AgentBlockCatalogService which works with the new Activities API).
	 *
	 * Returns false for:
	 *   - legacy composite containers (CLASS blacklist)
	 *   - NODE_TYPE values no catalog offers (SERVICE/TOOL/POOL)
	 *
	 * Storage CLASSes and FRAME pass: this predicate always runs in the REST catalog context, so the storage
	 * whitelist is unconditional here. Marta withholds both via {@see self::isNodeCompatibleFromRaw()}.
	 */
	public static function isNodeCompatible(ActivityDescription $d): bool
	{
		$class = $d->getClass();
		if (in_array($class, self::LEGACY_COMPOSITE_CLASS_BLACKLIST, true))
		{
			return false;
		}

		if (in_array($class, self::STORAGE_REST_VISIBLE_CLASS_WHITELIST, true))
		{
			return true;
		}

		return !in_array($d->getNodeType(), self::ALWAYS_HIDDEN_NODE_TYPES, true);
	}

	/**
	 * Visibility predicate for the raw activity arrays produced by CBPRuntime::searchActivitiesByType, used by
	 * BlockDescriptionService both for the Marta catalog and for reverse REST validation. The role cannot be
	 * inferred from the call site (getBlockSettings serves both), so source is passed in; it defaults to Marta
	 * (fail-closed), so a caller that forgets it keeps storage hidden rather than leaking it into the Marta
	 * catalog.
	 *
	 * Returns false for:
	 *   - legacy composite containers (CLASS blacklist)
	 *   - NODE_TYPE values no catalog offers (SERVICE/TOOL/POOL)
	 *   - Marta-hidden NODE_TYPE topologies (FRAME) - visible to REST but withheld from Marta
	 * Both whitelists apply only for source=Rest.
	 */
	public static function isNodeCompatibleFromRaw(array $activity, RequestSource $source = RequestSource::Marta): bool
	{
		$class = (string)($activity['CLASS'] ?? '');
		if (in_array($class, self::LEGACY_COMPOSITE_CLASS_BLACKLIST, true))
		{
			return false;
		}

		if (
			$source === RequestSource::Rest
			&& (
				in_array($class, self::STORAGE_REST_VISIBLE_CLASS_WHITELIST, true)
				|| in_array($class, self::REVERSE_VISIBLE_CLASS_WHITELIST, true)
			)
		)
		{
			return true;
		}

		$nodeType = (string)($activity['NODE_TYPE'] ?? '');
		if (in_array($nodeType, self::ALWAYS_HIDDEN_NODE_TYPES, true))
		{
			return false;
		}

		return !in_array($nodeType, self::MARTA_HIDDEN_NODE_TYPES, true);
	}

	/**
	 * Whether an EXCLUDED raw activity should still be admitted on the reverse path. EXCLUDED is checked before
	 * the visibility predicate, so a system AI agent's own EXCLUDED nodes would be cut before they reach it. The
	 * barrier is lifted only where all three hold: the CLASS is reverse-visible, the source is Rest, and the
	 * published baseline of the bound template already carries the block type. The last one is what keeps the
	 * bypass a read-back of what the template published rather than a licence to author a disabled block: an
	 * agent that invents a node of a switched-off CLASS finds no baseline entry for its type and stays refused.
	 *
	 * The storage whitelist is deliberately absent here - storage keeps its own EXCLUDED gate (setuptemplate /
	 * AiAgentSectionFlag). The caller still runs {@see self::isNodeCompatibleFromRaw()} afterwards: the bypass
	 * lifts EXCLUDED only, never the topology check.
	 *
	 * @param string $blockType lowercase catalog type-code of the activity, as the baseline indexes it
	 * @param array<string, true> $baselineBlockTypes block types of the published bound template, keyed lowercase.
	 *        Empty (the default) is fail-closed: a caller that cannot name a baseline lifts nothing
	 */
	public static function bypassesExcludedFromRaw(
		array $activity,
		RequestSource $source = RequestSource::Marta,
		string $blockType = '',
		array $baselineBlockTypes = [],
	): bool
	{
		return $source === RequestSource::Rest
			&& in_array((string)($activity['CLASS'] ?? ''), self::REVERSE_VISIBLE_CLASS_WHITELIST, true)
			&& isset($baselineBlockTypes[mb_strtolower($blockType)]);
	}

	/**
	 * Typed counterpart of {@see self::bypassesExcludedFromRaw()} for callers that hold ActivityDescription objects
	 * (the catalog-backed metadata resolver). Same whitelist, same source gate, same baseline demand - the rule
	 * lives in one place only.
	 *
	 * @param array<string, true> $baselineBlockTypes
	 */
	public static function bypassesExcluded(
		ActivityDescription $d,
		RequestSource $source = RequestSource::Marta,
		string $blockType = '',
		array $baselineBlockTypes = [],
	): bool
	{
		return self::bypassesExcludedFromRaw(
			['CLASS' => (string)$d->getClass()],
			$source,
			$blockType,
			$baselineBlockTypes,
		);
	}

	/**
	 * Returns true if the raw activity array carries a DEPRECATED=true flag.
	 */
	public static function isDeprecatedFromRaw(array $activity): bool
	{
		return ($activity['DEPRECATED'] ?? false) === true;
	}
}

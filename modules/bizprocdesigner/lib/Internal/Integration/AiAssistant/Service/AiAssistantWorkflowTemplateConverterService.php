<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Entity\AgentPortDefault;
use Bitrix\BizprocDesigner\Internal\Entity\NodeType;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRule;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRules;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph\UndirectedConnectivity;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\AgentBlockGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\FrameGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\FrameBlockMatcher;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class AiAssistantWorkflowTemplateConverterService
{
	use AgentBlockNamingTrait;

	/**
	 * Node-shape section a saved child carries, written by {@see NodesToTemplate::convertNodeToActivity} as
	 * `$activity['Node'] = $node`. It holds the frontend geometry (`position`/`dimensions`) and the inner
	 * `node` block (`type`, `frame*` styling) the reverse frame branch reads. There is no exported constant
	 * for this key in NodesToTemplate.
	 *
	 * Public because the geometry slice of the bound graph
	 * ({@see AgentWorkflowResolverService::getBoundBlocks()}) reads the same saved shape: a second spelling
	 * of the key would let the two readings of one storage format drift apart.
	 */
	public const NODE_ELEMENT = 'Node';

	/**
	 * Preset section a saved activity carries next to `Type`/`Name`/`Properties`: the id of the preset applied
	 * when the node was created ({@see \Bitrix\BizprocDesigner\Internal\Entity\ActivityData::toArray}, the same
	 * key the manual editor stamps and {@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes} reads
	 * back for the node visuals). NodesToTemplate exports no constant for it.
	 *
	 * Public for the same reason as {@see self::NODE_ELEMENT}.
	 */
	public const PRESET_ID_ELEMENT = 'PresetId';

	/**
	 * Host graph properties that {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}
	 * derives from the canonical rules - they are regenerated on the forward path and must not leak back to the
	 * agent as an opaque blob alongside the structured {@see AgentComplexRules} projection.
	 */
	private const COMPLEX_DERIVED_PROPERTY_NAMES = [
		'InputNames',
		'OutputNames',
		'Links',
		'FilterSettings',
		'FilterReturnPropertiesMap',
		'NotFilled',
	];

	/**
	 * Service properties the editor derives onto a saved activity that are NOT agent-editable settings:
	 * `EditorComment` (the editor note) and the computed `Return` descriptor (an ADDITIONAL_RESULT marker the
	 * activity's `getPropertiesDialogValues` writes on a UI save). A UI-saved trigger carries them in
	 * `Properties`; emitted as plain settings they fail {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingNameValidator}
	 * ('name is incorrect') on a naive re-send of `template.get`, so they are excluded from the agent settings.
	 */
	private const DERIVED_SERVICE_PROPERTY_NAMES = [
		'Return',
		'EditorComment',
	];

	/**
	 * State of the editor a block carries in its `Properties` and no agent authors: the collapsed/expanded flag
	 * the legacy designer writes onto the four container blocks that can be folded on the canvas (while, foreach,
	 * parallel, empty block), and the one the storage nodes write for their own filter section. It is the
	 * server's to keep - `_DesMinimized` is emitted as a setting it fails
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingNameValidator} for
	 * every block whose schema does not describe it, which is what refused a folded loop outright, and
	 * `IsExpanded` would let an agent fold a section a person unfolded (BRIEF § 3.5).
	 *
	 * Public because the write path has to restore exactly what this one withholds
	 * ({@see AiAssistantDraftConverterService::applyServerOwnedProperties()}): the agent is never handed the
	 * value, so it cannot send it back, and a name dropped on one side and unrestored on the other is a value
	 * lost on the first agent write.
	 */
	public const EDITOR_SERVICE_PROPERTY_NAMES = [
		'_DesMinimized',
		'IsExpanded',
	];

	/**
	 * Service keys carried inside a sub-action's / node-filter's child `Properties` that are NOT agent-editable
	 * settings. `Title` is the child's display title the forward path stamps from the node-action dictionary
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService::mapActionExpression},
	 * which restores it when absent) - surfacing it back verbatim as a `settings` key makes a naive re-send of
	 * `template.get` fail the sub-action name cross-check
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentComplexRulesValidator::validateNestedSettingNames}:
	 * `...settings.Title is not a known setting of this sub-action`). Dropped from the sub-action projection; the
	 * forward path restores it from the node-action name, so the round-trip stays idempotent. Distinct from the
	 * host-level {@see self::COMPLEX_DERIVED_PROPERTY_NAMES}/{@see self::DERIVED_SERVICE_PROPERTY_NAMES}, which
	 * never reach the nested sub-action `Properties`.
	 */
	private const SUB_ACTION_SERVICE_PROPERTY_NAMES = [
		NodesToTemplate::PROPERTY_TITLE,
	];

	private readonly AgentBlockMetadataResolver $metadataResolver;

	private ?AgentBlockCollection $agentBlocks = null;
	private ?AgentConnectionCollection $agentConnections = null;
	private ?ActivityRegistry $activityRegistry = null;
	private ?ComplexActivityService $complexActivityService = null;

	public function __construct(?AgentBlockMetadataResolver $metadataResolver = null)
	{
		$this->metadataResolver = $metadataResolver ?? new AgentBlockMetadataResolver();
	}

	public function getAgentTemplate(): ?AgentTemplate
	{
		if ($this->agentBlocks && $this->agentConnections)
		{
			return new AgentTemplate(
				blocks: $this->agentBlocks,
				connections: $this->agentConnections,
			);
		}

		return null;
	}

	/**
	 * Reverse of the forward converter. The frame overlay is a read-path concern gated by $source: only the
	 * external REST agent receives frames ({@see RequestSource::Rest}); Marta never does (read isolation), so
	 * the default keeps a frame out unless the REST caller explicitly opts in.
	 */
	public function convertFromTemplateArrayToAgentTemplate(
		array $template,
		RequestSource $source = RequestSource::Marta,
	): Result
	{
		$this->agentBlocks = null;
		$this->agentConnections = null;

		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Module "bizproc" is not installed.'));
		}

		$result = $this->convertTemplateArrayToAgentBlocks($template, $source);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->convertTemplateArrayToAgentConnections($template);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new Result();
	}

	private function convertTemplateArrayToAgentBlocks(array $template, RequestSource $source): Result
	{
		$rootElement = $template[0] ?? [];
		$rootElementType = $rootElement[NodesToTemplate::ELEMENT_TYPE] ?? null;
		if ($rootElementType !== NodesToTemplate::ROOT_NODE_TYPE)
		{
			return (new Result())
				->addError(new Error('Invalid template format: root element must be ' . NodesToTemplate::ROOT_NODE_TYPE))
			;
		}

		$rootElementChildren = $rootElement[NodesToTemplate::ELEMENT_CHILDREN] ?? [];
		if (!is_array($rootElementChildren)) {

			return (new Result())
				->addError(new Error('Invalid template format: root element children must be an array'))
			;
		}

		// Frame membership is computed from the saved geometry of the OTHER blocks, so their real rectangles
		// (top-left position sized by widget type) are gathered once up front. Only the REST agent receives
		// frames, so Marta never pays for this pass.
		$blockRects = $source === RequestSource::Rest
			? $this->collectNonFrameBlockRects($rootElementChildren)
			: []
		;

		// Geometry alone can place blocks that are not linked to each other inside one frame rectangle
		// (a hand-drawn UI frame, manual edits). The forward membership validator requires members to form a
		// connected sub-graph, so the reverse projection filters them by the same undirected connectivity - the
		// graph edges are read once here from the saved Links.
		$memberAdjacency = $source === RequestSource::Rest
			? $this->collectMemberAdjacency($rootElement)
			: []
		;

		$agentBlocks = new AgentBlockCollection();
		foreach ($rootElementChildren as $child)
		{
			$type = $child[NodesToTemplate::ELEMENT_TYPE] ?? null;
			$id = $child[NodesToTemplate::ELEMENT_NAME] ?? null;
			$properties = $child[NodesToTemplate::ELEMENT_PROPERTIES] ?? [];
			$result = $this->validateBlock($type, $id, $properties);
			if (!$result->isSuccess())
			{
				return $result;
			}

			// A frame overlay is a presentational block, not an activity: its membership/styling are read from
			// the saved node geometry, and it is surfaced only to the REST agent (Marta stays frame-free on read).
			if ($this->isFrameChild($child))
			{
				if ($source === RequestSource::Rest)
				{
					$frameBlock = $this->makeFrameAgentBlock($child, (string)$type, (string)$id, $blockRects, $memberAdjacency);
					if ($frameBlock !== null)
					{
						$agentBlocks->add($frameBlock);
					}
				}

				continue;
			}

			$title = (string)($properties[NodesToTemplate::PROPERTY_TITLE] ?? '');
			// The saved PresetId is the first source: both the manual editor and the agent write path stamp it on
			// the activity. A node saved before the field was written does not carry it, and for those the preset
			// is recovered from the Properties.Document the preset applies, matched against the real presets of
			// the activity. The system name (caption) of a multi-preset node is the preset name, so the
			// Properties.Title normalization runs against that name.
			$presetId = $this->normalizePresetId($child[self::PRESET_ID_ELEMENT] ?? null)
				?? $this->metadataResolver->resolvePresetId((string)$type, $this->extractDocument($properties));
			$systemName = $this->metadataResolver->resolveSystemName((string)$type, $presetId);

			$complexRulePropertyName = $this->resolveComplexRulePropertyName($type);
			$complexRules = $complexRulePropertyName !== null
				? $this->buildComplexRulesProjection($complexRulePropertyName, $properties)
				: null
			;

			$agentBlocks->add(
				new AgentBlock(
					type: $this->toCanonicalBlockType($type),
					// Ручной редактор кладёт системное имя (caption) в Properties.Title даже для
					// непереименованных нод. Отдаём агенту пустой title, когда пользовательское имя
					// совпадает с системным (нормализованно) — симметрично прямому пути, где override
					// не пишется.
					title: $this->isSameNodeName($title, $systemName) ? '' : $title,
					id: $id,
					settings: $complexRules !== null
						? $this->convertComplexPropertiesToAgentSettings($properties, $complexRulePropertyName)
						: $this->convertPropertiesToAgentSettings($properties),
					description: $this->extractDescription($properties),
					presetId: $presetId,
					rules: $complexRules,
					returnProperties: $this->buildReturnPropertiesProjection($type, $properties),
				),
			);
		}

		$this->agentBlocks = $agentBlocks;

		return new Result();
	}

	/**
	 * Whether a saved child is a frame overlay. Detected by the frontend node marker `Node.type == 'frame'`
	 * ({@see NodeType::Frame}) that {@see NodesToTemplate::convertNodeToActivity} preserves, not by the activity
	 * class (a frame is stored as a plain `EmptyBlockActivity`, so the class alone is ambiguous). Null-safe.
	 */
	private function isFrameChild(array $child): bool
	{
		$node = $child[self::NODE_ELEMENT] ?? null;

		return is_array($node) && ($node['type'] ?? null) === NodeType::Frame->value;
	}

	/**
	 * Builds the agent-facing frame overlay. Its logical membership is recomputed from the saved geometry
	 * (ALG-03, the single centre-inside criterion shared with {@see FramePostLayoutChecker}), and its styling
	 * comes from the saved `node.frame*` section. The frame keeps the same identity as any other block: its id
	 * is the child `Name` verbatim, and it re-declares the {@see FrameBlockMatcher::FRAME_PRESET_ID} preset so a
	 * naive re-send of `template.get` is recognised as a frame again by the forward path (idempotent round-trip).
	 *
	 * Returns null for a degenerate frame whose computed membership is empty (unreadable geometry, or no block
	 * captured centre-inside): such a frame would reach the agent without `memberBlockIds` ({@see AgentBlock::toArray}
	 * suppresses the empty list) and fail the forward membership validator on a verbatim re-send
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentFrameMembershipValidator}:
	 * 'memberBlockIds is empty'). It is dropped (graceful skip) so the round-trip stays idempotent, symmetric to
	 * the orphan-link guard in {@see self::convertTemplateArrayToAgentConnections}.
	 *
	 * Round-trip idempotency is guaranteed only for frames produced by the forward path. A frame authored outside
	 * the agent (drawn in the UI) with overlapping frames, geometrically disconnected groups, or content beyond the
	 * length bound may still be rejected by the forward validator on a verbatim re-send: the reverse projection
	 * normalises what it safely can (connectivity filter, degenerate-frame skip), not arbitrary hand-drawn geometry.
	 *
	 * @param array<string, array{x: int, y: int, width: int, height: int}> $blockRects
	 * @param array<string, list<string>> $memberAdjacency undirected block graph, for the connectivity filter
	 */
	private function makeFrameAgentBlock(
		array $child,
		string $type,
		string $id,
		array $blockRects,
		array $memberAdjacency,
	): ?AgentBlock
	{
		$node = is_array($child[self::NODE_ELEMENT] ?? null) ? $child[self::NODE_ELEMENT] : [];
		$style = is_array($node['node'] ?? null) ? $node['node'] : [];

		$memberBlockIds = $this->computeFrameMembers($node, $blockRects, $id, $memberAdjacency);
		if ($memberBlockIds === [])
		{
			return null;
		}

		return new AgentBlock(
			type: $this->toCanonicalBlockType($type),
			title: $this->readFrameTitle($style, $child),
			id: $id,
			settings: new AgentSettingCollection(),
			presetId: FrameBlockMatcher::FRAME_PRESET_ID,
			memberBlockIds: $memberBlockIds,
			frameColorName: $this->readNonEmptyString($style, 'frameColorName'),
			frameContent: $this->readNonEmptyString($style, 'frameContent'),
		);
	}

	/**
	 * Members of a frame: every non-frame block whose saved top-left position places its centre inside the
	 * frame rectangle (ALG-03, {@see FrameGeometry::contains} - the same predicate the post-layout checker
	 * uses, so the round-trip stays idempotent). A frame with no readable geometry degrades to no members
	 * instead of failing (criterion 9). The frame never groups itself.
	 *
	 * The geometric hit-test can capture blocks that are not linked to each other, which the forward
	 * membership validator rejects as disconnected. So the result is filtered to the largest connected
	 * component over the block graph ({@see UndirectedConnectivity}, the same criterion the validator applies),
	 * keeping the reverse output always re-sendable. A fully connected set (an agent-built frame) is a no-op.
	 *
	 * @param array<string, array{x: int, y: int, width: int, height: int}> $blockRects
	 * @param array<string, list<string>> $memberAdjacency
	 * @return list<string>
	 */
	private function computeFrameMembers(
		array $frameNode,
		array $blockRects,
		string $frameId,
		array $memberAdjacency,
	): array
	{
		$rect = $this->extractFrameRect($frameNode);
		if ($rect === null)
		{
			return [];
		}

		$members = [];
		foreach ($blockRects as $blockId => $blockRect)
		{
			if ($blockId === $frameId)
			{
				continue;
			}

			if (FrameGeometry::contains($rect, $blockRect['x'], $blockRect['y'], $blockRect['width'], $blockRect['height']))
			{
				$members[] = $blockId;
			}
		}

		return UndirectedConnectivity::largestConnectedComponent($members, $memberAdjacency);
	}

	/**
	 * Undirected block adjacency read once from the saved root Links, in the same edge model the forward
	 * frame-membership validator uses. Each link endpoint is "blockId:portId"; only the block id matters for
	 * connectivity, so the port is dropped. Malformed links are ignored.
	 *
	 * @param array<array-key, mixed> $rootElement
	 * @return array<string, list<string>>
	 */
	private function collectMemberAdjacency(array $rootElement): array
	{
		$links = $rootElement[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS] ?? [];
		if (!is_array($links))
		{
			return [];
		}

		$connections = [];
		foreach ($links as $link)
		{
			if (!is_array($link))
			{
				continue;
			}

			[$sourceBlockId] = $this->parseLinkEndpoint($link[0] ?? null, AgentPortDefault::SOURCE_PORT_ID);
			[$destinationBlockId] = $this->parseLinkEndpoint($link[1] ?? null, AgentPortDefault::TARGET_PORT_ID);
			$connections[] = [
				'sourceBlockId' => $sourceBlockId,
				'destinationBlockId' => $destinationBlockId,
			];
		}

		return UndirectedConnectivity::buildAdjacency($connections);
	}

	/**
	 * Real rectangles of every non-frame block, keyed by block id (the child `Name`): the saved top-left
	 * `Node.position` sized by the block's widget type ({@see AgentBlockGeometry::hitTestSizeForType}), so the
	 * centre-inside membership hit-test reasons about the same block centre the forward post-layout checker
	 * uses. The type-only size keeps the reverse projection independent of the saved port/rule counts, so the
	 * round-trip stays idempotent. Read strictly null-safe; blocks with no readable geometry are skipped.
	 *
	 * @param array<int, mixed> $children
	 * @return array<string, array{x: int, y: int, width: int, height: int}>
	 */
	private function collectNonFrameBlockRects(array $children): array
	{
		$rects = [];
		foreach ($children as $child)
		{
			if (!is_array($child) || $this->isFrameChild($child))
			{
				continue;
			}

			$id = $child[NodesToTemplate::ELEMENT_NAME] ?? null;
			if (!is_string($id) || $id === '')
			{
				continue;
			}

			$node = $child[self::NODE_ELEMENT] ?? null;
			$position = is_array($node) ? ($node['position'] ?? null) : null;
			if (!is_array($position) || !isset($position['x'], $position['y']))
			{
				continue;
			}

			$type = $child[NodesToTemplate::ELEMENT_TYPE] ?? '';
			$size = AgentBlockGeometry::hitTestSizeForType(is_string($type) ? $type : '', $this->getActivityRegistry());
			$rects[$id] = [
				'x' => (int)$position['x'],
				'y' => (int)$position['y'],
				'width' => $size['width'],
				'height' => $size['height'],
			];
		}

		return $rects;
	}

	/**
	 * Frame rectangle from the saved `Node.position`/`Node.dimensions`, strictly null-safe. Returns null when
	 * either is missing/malformed so the caller degrades to an empty membership without failing.
	 *
	 * @return array{x: int, y: int, width: int, height: int}|null
	 */
	private function extractFrameRect(array $frameNode): ?array
	{
		$position = $frameNode['position'] ?? null;
		$dimensions = $frameNode['dimensions'] ?? null;
		if (!is_array($position) || !is_array($dimensions))
		{
			return null;
		}

		if (!isset($position['x'], $position['y'], $dimensions['width'], $dimensions['height']))
		{
			return null;
		}

		return [
			'x' => (int)$position['x'],
			'y' => (int)$position['y'],
			'width' => (int)$dimensions['width'],
			'height' => (int)$dimensions['height'],
		];
	}

	/**
	 * Frame title: the saved node title (the value the forward path stamps from the agent title / system name),
	 * falling back to `Properties.Title`. Empty when neither is set.
	 *
	 * @param array<array-key, mixed> $style saved `Node.node` section
	 * @param array<array-key, mixed> $child saved child
	 */
	private function readFrameTitle(array $style, array $child): string
	{
		$nodeTitle = $this->readNonEmptyString($style, 'title');
		if ($nodeTitle !== null)
		{
			return $nodeTitle;
		}

		$properties = $child[NodesToTemplate::ELEMENT_PROPERTIES] ?? [];

		return is_array($properties) ? (string)($properties[NodesToTemplate::PROPERTY_TITLE] ?? '') : '';
	}

	/**
	 * @param array<array-key, mixed> $source
	 */
	private function readNonEmptyString(array $source, string $key): ?string
	{
		$value = $source[$key] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * The template carries the activity class name (PascalCase, e.g. `CrmDealComplexActivity`), but the
	 * agent-facing catalog (`catalog.block.list`) and the forward validator ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlockValidator})
	 * key blocks by the canonical catalog code - the lowercased class name (`crmdealcomplexactivity`), which is
	 * exactly how {@see \CBPRuntime::searchActivitiesByType()} indexes them. Emitting the canonical code here
	 * keeps `template.get` symmetric with the forward path, so a naive re-send of the reverse output is not
	 * rejected with `type is incorrect type`. Applies uniformly to complex and simple nodes.
	 *
	 * Only the outward `AgentBlock::type` is normalized; the in-loop `$type` still drives complex-wrapper
	 * detection and configurator resolution on the original class name, which is where those lookups are
	 * exercised on the forward path.
	 */
	private function toCanonicalBlockType(string $type): string
	{
		return mb_strtolower($type);
	}

	private function convertTemplateArrayToAgentConnections(array $template): Result
	{
		$rootElement = $template[0] ?? [];
		$links = $rootElement[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS] ?? [];
		if (!is_array($links))
		{
			return (new Result())
				->addError(new Error('Invalid template format: root element links must be an array'))
			;
		}

		// Ids of the blocks actually emitted to the agent, so a link can be rejected when either endpoint is gone.
		$knownBlockIds = array_fill_keys($this->agentBlocks?->getIds() ?? [], true);

		$agentConnections = new AgentConnectionCollection();
		foreach ($links as $link)
		{
			[$sourceBlockId, $sourcePortId] = $this->parseLinkEndpoint($link[0] ?? null, AgentPortDefault::SOURCE_PORT_ID);
			[$destinationBlockId, $targetPortId] = $this->parseLinkEndpoint($link[1] ?? null, AgentPortDefault::TARGET_PORT_ID);

			// A broken/legacy link (null/empty endpoint) is skipped, not fatal: one corrupt link must not make the
			// whole template unreadable for the agent. Degrades softly like the orphan-link guard below, which it
			// complements - this catches a missing/malformed endpoint, that one a valid-but-deleted block.
			if (!$this->isLinkValid($sourceBlockId, $destinationBlockId))
			{
				continue;
			}

			// Drop orphan links left by editor autosave (an endpoint block was deleted): filter by block existence,
			// not by port, so loop-back (ForEach i1, While o0) and aux links between present blocks survive.
			if (!isset($knownBlockIds[$sourceBlockId], $knownBlockIds[$destinationBlockId]))
			{
				continue;
			}

			$agentConnections->add(
				new AgentConnection(
					destinationBlockId: $destinationBlockId,
					sourceBlockId: $sourceBlockId,
					sourcePortId: $sourcePortId,
					targetPortId: $targetPortId,
				),
			);
		}

		$this->agentConnections = $agentConnections;

		return new Result();
	}

	private function convertPropertiesToAgentSettings(array $properties): AgentSettingCollection
	{
		$excluded = [
			NodesToTemplate::PROPERTY_TITLE,
			...self::DERIVED_SERVICE_PROPERTY_NAMES,
			...self::EDITOR_SERVICE_PROPERTY_NAMES,
		];

		$settings = new AgentSettingCollection();

		foreach ($properties as $name => $value)
		{
			// Title/EditorComment уходят агенту отдельными полями (title/description), а Return — служебный
			// дескриптор редактора; ни то, ни другое не дублируется в общей куче настроек блока.
			if (in_array((string)$name, $excluded, true))
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: (string)$name,
				value: is_array($value) ? $value : (string)$value,
			));
		}

		return $settings;
	}

	/**
	 * Flat settings for a complex node exclude the canonical rules property (surfaced as the structured
	 * {@see AgentComplexRules} projection instead of an opaque blob), the graph properties derived by the
	 * rule-engine ({@see self::COMPLEX_DERIVED_PROPERTY_NAMES}) and the non-editable service properties a UI save
	 * stamps onto the host ({@see self::DERIVED_SERVICE_PROPERTY_NAMES}: `Return`/`EditorComment`) - the latter
	 * symmetric to {@see self::convertPropertiesToAgentSettings}, so a UI-saved complex node stays idempotent on a
	 * naive re-send of `template.get` (surfaced as plain settings they would fail
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingNameValidator}); the
	 * remaining host properties still pass through unchanged.
	 */
	private function convertComplexPropertiesToAgentSettings(
		array $properties,
		string $rulePropertyName,
	): AgentSettingCollection
	{
		$excluded = [
			NodesToTemplate::PROPERTY_TITLE,
			$rulePropertyName,
			...self::COMPLEX_DERIVED_PROPERTY_NAMES,
			...self::DERIVED_SERVICE_PROPERTY_NAMES,
		];

		$settings = new AgentSettingCollection();
		foreach ($properties as $name => $value)
		{
			if (in_array((string)$name, $excluded, true))
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: (string)$name,
				value: is_array($value) ? $value : (string)$value,
			));
		}

		return $settings;
	}

	/**
	 * Read-only projection of the block's output (return) properties, shape
	 * `[{id, name, type, multiple, default}]`. The single source of truth is
	 * {@see \CBPRuntime::getActivityReturnProperties()} - the exact call the node editor uses
	 * ({@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes::transformBlockActivities}) - so the
	 * agent sees the same outputs as the UI, not a parallel description.
	 *
	 * The method is fed the FULL block instance (`Type` + its `Properties`), not a bare code: a trigger's
	 * outputs (`ReturnDocument`/`ChangedFields`) live in an `ADDITIONAL_RESULT` marker the engine expands from
	 * `Properties['Return']`, so passing only the type yields an empty set. `id` is taken from the array key,
	 * exactly as the node editor does ({@see TemplateToNodes}). Raw outputs (incl. `default`) are emitted as-is;
	 * the per-document-type filter is the agent's concern, not the server's.
	 *
	 * @return list<array{id: string, name: string, type: string, multiple: bool, default: mixed}>
	 */
	private function buildReturnPropertiesProjection(mixed $type, array $properties): array
	{
		if (!is_string($type) || $type === '')
		{
			return [];
		}

		try
		{
			$rawReturnProperties = \CBPRuntime::getRuntime()->getActivityReturnProperties([
				'Type' => $type,
				'Properties' => $properties,
			]);
		}
		catch (\Throwable)
		{
			return [];
		}

		$projection = [];
		foreach ($rawReturnProperties as $id => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			$projection[] = [
				'id' => (string)$id,
				'name' => (string)($property['Name'] ?? ''),
				'type' => (string)($property['Type'] ?? ''),
				'multiple' => (bool)($property['Multiple'] ?? false),
				'default' => $property['Default'] ?? null,
			];
		}

		return $projection;
	}

	/**
	 * Rule property name of a complex-wrapper node (canonically `Rules`), or null when the block is not a
	 * complex node. Resolved by name through {@see ComplexActivityService::resolveRulePropertyName()} - the
	 * same lookup the manual editor and the forward converter use, so every direction reads one property
	 * regardless of the order of the properties map.
	 */
	private function resolveComplexRulePropertyName(mixed $type): ?string
	{
		if (!is_string($type) || $type === '' || !$this->getActivityRegistry()->isComplexWrapper($type))
		{
			return null;
		}

		try
		{
			$configurator = \CBPActivity::createConfigurator($type, []);
			$name = $this->getComplexActivityService()->resolveRulePropertyName($configurator);
			if ($name !== null)
			{
				return $name;
			}
		}
		catch (\Throwable)
		{
		}

		return ComplexActivityService::RULES_PARAM;
	}

	/**
	 * Reverse of the forward path (ALG-02): parses the node's own canonical `Rules` property (never the
	 * derived `Children`) into the editable nested projection (DTO-01), reusing the domain
	 * {@see PortRuleDto::fromArray} parser and reverse-mapping each construction to its typed projection.
	 * Condition values (incl. `{=...}`/`{{=...}}` bizproc expressions) are carried verbatim.
	 *
	 * Returns null when the property is missing/malformed so the block degrades to the plain settings path
	 * instead of dropping the read.
	 */
	private function buildComplexRulesProjection(string $rulePropertyName, array $properties): ?AgentComplexRules
	{
		$rawRules = $properties[$rulePropertyName] ?? null;
		if (!is_array($rawRules) || $rawRules === [])
		{
			return null;
		}

		try
		{
			$portRules = [];
			foreach ($rawRules as $rawPortRule)
			{
				if (!is_array($rawPortRule) || !is_string($rawPortRule['portId'] ?? null))
				{
					continue;
				}

				$portRule = PortRuleDto::fromArray($rawPortRule);
				$rules = $this->mapPortRuleToProjection($portRule);
				if ($rules !== [])
				{
					$portRules[$portRule->portId] = $rules;
				}
			}
		}
		catch (\Throwable)
		{
			return null;
		}

		return $portRules === [] ? null : new AgentComplexRules($portRules);
	}

	/**
	 * @return list<AgentComplexRule>
	 */
	private function mapPortRuleToProjection(PortRuleDto $portRule): array
	{
		$rules = [];
		foreach ($portRule->rules as $rule)
		{
			$constructions = [];
			foreach ($rule->constructions as $construction)
			{
				$projection = $this->mapConstructionToProjection($construction);
				if ($projection !== null)
				{
					$constructions[] = $projection;
				}
			}

			if ($constructions !== [])
			{
				$rules[] = new AgentComplexRule($rule->id, $constructions);
			}
		}

		return $rules;
	}

	private function mapConstructionToProjection(ConstructionDto $construction): ?AgentComplexConstruction
	{
		$expression = $construction->expression;
		$type = $construction->constructionType;

		if ($type->isCondition() && $expression instanceof ConditionExpressionDto)
		{
			return new AgentComplexConstruction(
				AgentComplexConstruction::TYPE_CONDITION,
				$this->mapConditionExpressionToProjection($expression, $type),
			);
		}

		if ($type === ConstructionType::ACTION && $expression instanceof ActionExpressionDto)
		{
			return new AgentComplexConstruction(
				AgentComplexConstruction::TYPE_ACTION,
				$this->mapActionExpressionToProjection($expression),
			);
		}

		if ($type === ConstructionType::FILTER && $expression instanceof ActionExpressionDto)
		{
			return new AgentComplexConstruction(
				AgentComplexConstruction::TYPE_FILTER,
				$this->mapFilterExpressionToProjection($expression),
			);
		}

		if ($type === ConstructionType::OUTPUT && $expression instanceof OutputExpressionDto)
		{
			return new AgentComplexConstruction(
				AgentComplexConstruction::TYPE_OUTPUT,
				$this->mapOutputExpressionToProjection($expression),
			);
		}

		return null;
	}

	/**
	 * Condition projection (DTO-03): field metadata and value are carried verbatim; the domain
	 * `condition:if`/`and`/`or` type collapses into a single `condition` whose DNF joiner (`OR` opens a new
	 * group, otherwise `AND`) is exactly what the forward path re-expands into `if`/`and`/`or` by position.
	 */
	private function mapConditionExpressionToProjection(
		ConditionExpressionDto $expression,
		ConstructionType $constructionType,
	): array
	{
		return [
			'field' => $expression->field?->jsonSerialize(),
			'operator' => $expression->operator,
			'value' => $expression->value,
			'joiner' => $constructionType === ConstructionType::OR_CONDITION ? 'OR' : 'AND',
		];
	}

	/**
	 * Action projection (DTO-01 `{activityCode, settings, document?, auxPortId?, auxPortTitle?}`): the activity
	 * code prefers the agent-facing `actionId` (falls back to the resolved child `Type`) so the forward path
	 * re-resolves the same node-action; the child `Properties` become `settings` (bizproc expressions preserved),
	 * minus the service {@see self::SUB_ACTION_SERVICE_PROPERTY_NAMES} (`Title`) so a re-send passes the sub-action
	 * name cross-check. The optional `auxPortId`/`auxPortTitle` of a sub-action (a manual-editor aux branch the forward
	 * path materialises into `Properties.auxPort`) are carried back so a place->read->edit->save round-trip of a
	 * hand-built template does not silently drop the aux output. AI-generated sub-actions never set them, so the
	 * pure-AI round-trip is unchanged (additive/BC).
	 */
	private function mapActionExpressionToProjection(ActionExpressionDto $expression): array
	{
		$activityData = is_array($expression->activityData) ? $expression->activityData : [];

		$activityCode = $expression->actionId;
		if (!is_string($activityCode) || $activityCode === '')
		{
			$activityCode = (string)($activityData['Type'] ?? '');
		}

		$projection = [
			'activityCode' => $activityCode,
			'settings' => $this->filterSubActionSettings(
				is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [],
			),
		];

		$document = $expression->document;
		if (!is_string($document) || $document === '')
		{
			$document = $activityData['Document'] ?? null;
		}
		if (is_string($document) && $document !== '')
		{
			$projection['document'] = $document;
		}

		if (is_string($expression->auxPortId) && $expression->auxPortId !== '')
		{
			$projection['auxPortId'] = $expression->auxPortId;

			if (is_string($expression->auxPortTitle) && $expression->auxPortTitle !== '')
			{
				$projection['auxPortTitle'] = $expression->auxPortTitle;
			}
		}

		return $projection;
	}

	/**
	 * Filter projection (DTO-01 `{activityCode, settings, filterId?}`): the backing activity class becomes
	 * `activityCode` and its `Properties` become `settings` minus the service
	 * {@see self::SUB_ACTION_SERVICE_PROPERTY_NAMES} (`Title`); the top-level `ReturnProperties` are folded into
	 * `settings` because the forward path re-reads them from there - this is what the host
	 * `FilterSettings`/`FilterReturnPropertiesMap` are derived from, so the round-trip stays lossless from
	 * `Rules` alone (symmetric to the manual editor's `loadSettingsAction`, which never reads the host filter
	 * properties either). `filterId` carries the filter's stable identity so consumer references survive a
	 * re-forward (see below).
	 */
	private function mapFilterExpressionToProjection(ActionExpressionDto $expression): array
	{
		$activityData = is_array($expression->activityData) ? $expression->activityData : [];

		$settings = $this->filterSubActionSettings(
			is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [],
		);
		if (is_array($activityData['ReturnProperties'] ?? null) && !isset($settings['ReturnProperties']))
		{
			$settings['ReturnProperties'] = $activityData['ReturnProperties'];
		}

		$projection = [
			'activityCode' => (string)($activityData['Type'] ?? ''),
			'settings' => $settings,
		];

		// Carry the filter's stable identity (its backing-activity `Name`) so the forward path reuses it as the
		// filter id instead of minting a random one. `ConvertRuleCommand` derives `filterId` from this `Name`, and
		// consumers reference the filter as `{=<filterId>:Document}` (resolved to `TargetFilterId`). Without a
		// stable id a re-forward regenerates it and every such reference is left dangling. Fallback to generation
		// stays when the id is absent (a genuinely new filter).
		$filterId = $activityData['Name'] ?? null;
		if (is_string($filterId) && $filterId !== '')
		{
			$projection['filterId'] = $filterId;
		}

		return $projection;
	}

	/**
	 * Drops the sub-action / node-filter service keys ({@see self::SUB_ACTION_SERVICE_PROPERTY_NAMES}) from the
	 * child `Properties` before they become the agent-facing `settings`. Every other property passes through
	 * unchanged, so genuine settings (bizproc expressions included) are preserved.
	 *
	 * @param array<array-key, mixed> $properties
	 *
	 * @return array<array-key, mixed>
	 */
	private function filterSubActionSettings(array $properties): array
	{
		foreach (self::SUB_ACTION_SERVICE_PROPERTY_NAMES as $serviceKey)
		{
			unset($properties[$serviceKey]);
		}

		return $properties;
	}

	private function mapOutputExpressionToProjection(OutputExpressionDto $expression): array
	{
		return [
			'portId' => $expression->portId,
			'title' => $expression->title,
		];
	}

	private function getActivityRegistry(): ActivityRegistry
	{
		return $this->activityRegistry ??= new ActivityRegistry();
	}

	private function getComplexActivityService(): ComplexActivityService
	{
		return $this->complexActivityService ??= BizprocContainer::instance()->getComplexActivityService();
	}

	/**
	 * Разбирает конец связи "blockId:portId" за один проход. portId возвращается только для
	 * нестандартных портов — дефолт (o0/i0) заменяется на null, чтобы round-trip линейных
	 * цепочек оставался симметричным прямому пути (BC).
	 *
	 * @return array{0: ?string, 1: ?string} [$blockId, $portId]
	 */
	private function parseLinkEndpoint(mixed $linkWithPort, string $defaultPortId): array
	{
		if (!is_string($linkWithPort))
		{
			return [null, null];
		}

		$parts = explode(NodesToTemplate::LINK_DELIMITER, $linkWithPort, 2);
		$blockId = $parts[0] ?? null;
		$portId = $parts[1] ?? null;
		if ($portId === '' || $portId === $defaultPortId)
		{
			$portId = null;
		}

		return [$blockId, $portId];
	}

	private function isLinkValid(?string $sourceBlockId, ?string $destinationBlockId): bool
	{
		return $this->isBlockIdentifierValid($sourceBlockId)
			&& $this->isBlockIdentifierValid($destinationBlockId);
	}

	private function isBlockIdentifierValid(?string $blockIdentifier): bool
	{
		return is_string($blockIdentifier) && $blockIdentifier !== '';
	}

	private function validateBlock(mixed $type, mixed $id, mixed $properties): Result
	{
		$result = new Result();

		if (!is_string($id) || $id === '')
		{
			$result->addError(new Error('Block Name is not specified'));
		}

		if (!is_string($type) || $type === '')
		{
			$result->addError(new Error('Block Type is not specified'));
		}

		if (!is_array($properties))
		{
			$result->addError(new Error('Block Properties are not array'));
		}

		return $result;
	}
}

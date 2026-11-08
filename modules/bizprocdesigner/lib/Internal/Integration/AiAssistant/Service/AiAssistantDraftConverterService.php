<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Public\Service\Activity\ActivityNameGeneratorService;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\OutputExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\RuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand;
use Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommandResult;
use Bitrix\BizprocDesigner\Internal\Exception\CommandValidateException;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Entity\AgentPortDefault;
use Bitrix\BizprocDesigner\Internal\Entity\Block;
use Bitrix\BizprocDesigner\Internal\Entity\FrameData;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\PortCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Connection;
use Bitrix\BizprocDesigner\Internal\Entity\NodeType;
use Bitrix\BizprocDesigner\Internal\Entity\Port;
use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexRule;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\AgentBlockGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\FrameGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\TopologyLayoutEngine;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\BlockIdGrammar;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\FrameBlockMatcher;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationProfile;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Security\Random;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AiAssistantDraftConverterService
{
	use AgentBlockNamingTrait;

	private const PORT_POSITION = 1;
	/**
	 * Titles the complex node's dynamic ports the same way the manual editor does (shared frontend
	 * contract COMPLEX_NODE_PORT_LABELS in the chart extension): input rule ports are G{n}, output rule
	 * ports are E{n}, numbered by position from 1. The node editor matches/parses complex ports by these
	 * titles, so a port without one breaks the editor.
	 */
	private const COMPLEX_PORT_LABEL_INPUT = 'G';
	private const COMPLEX_PORT_LABEL_OUTPUT = 'E';
	private const START_POSITION_X = 300;
	private const START_POSITION_Y = 50;
	private const BLOCK_MARGIN = 100;

	/** Shared diagnostic channel with the catalog services (no new log channel). */
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';
	private const COMPLEX_OUTCOME_MATERIALIZED = 'materialized';
	private const COMPLEX_OUTCOME_FALLBACK = 'fallback';

	private string $uniqueBlockSalt;
	private ?ActivityRegistry $activityRegistry = null;
	private ?NodeTopologyResolver $nodeTopologyResolver = null;
	private readonly AgentBlockMetadataResolver $metadataResolver;
	private ?ComplexActivityService $complexActivityService = null;
	private ?ActivityNameGeneratorService $nameGeneratorService = null;
	private ?Searcher $searcher = null;
	private ?LoggerInterface $logger = null;
	/** @var array<string, array<string, ActivityDescription>> preset-applied node actions per block type */
	private array $nodeActionDescriptionCache = [];
	/** @var array<string, list<string>> ADDITIONAL_RESULT marker keys per normalized activity code */
	private array $additionalResultKeysCache = [];
	/** @var array<string, list<string>> passthrough setting names per (normalized activity code, document type) */
	private array $passthroughSettingNamesCache = [];
	/** @var array<string, array<string, mixed>> enforced setting values per (normalized code, document type) */
	private array $enforcedSettingValuesCache = [];
	/**
	 * Whether the identifiers of the graph being converted were judged by the grammar on the way in
	 * ({@see self::makeActivityId}). Read from the profile of the entry rather than from the source itself, so
	 * the converter asks the very question the check asks ({@see ValidationProfile::enforcesBlockIdGrammar()}).
	 */
	private bool $idsHeldToGrammar = false;
	/** Bound template of the graph being converted; 0 when the caller has none ({@see self::getBaselineActivities}). */
	private int $baselineTemplateId = 0;
	/** Draft the graph being converted was read from; 0 when the caller names none ({@see self::carriesSourceName}). */
	private int $sourceDraftId = 0;
	/**
	 * @var array<string, array{Type: string, Properties: array}>|null names of the source graph, read at most once
	 *      per conversion ({@see self::readSourceNames})
	 */
	private ?array $sourceNames = null;
	/** @var array<int, array|null> published TEMPLATE per id, read lazily and at most once per id */
	private array $baselineTemplateById = [];
	/** @var array<int, array<string, array{Type: string, Properties: array}>> published nodes by name, per id */
	private array $baselineActivitiesById = [];

	public function __construct(?AgentBlockMetadataResolver $metadataResolver = null)
	{
		$this->metadataResolver = $metadataResolver ?? new AgentBlockMetadataResolver();
		$this->uniqueBlockSalt = Random::getStringByAlphabet(
			8,
			Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER,
		);
	}

	/**
	 * @param array<string, array{
	 *     type: string,
	 *     presetId: string|null,
	 *     dimensions: array{width: int|null, height: int|null}
	 * }> $boundBlocks
	 */
	public function covertFromAgentInput(
		int $draftId,
		int $templateId,
		int $userId,
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		array $documentType = [],
		RequestSource $source = RequestSource::Marta,
		array $boundBlocks = [],
	): Draft
	{
		// The bound template is the baseline a derived value is restored from when its dialog refuses to answer
		// ({@see self::applyAdditionalResult}), and one of the two source graphs a name is judged against
		// ({@see self::carriesSourceName}); nothing else on the ordinary path reads it.
		$this->baselineTemplateId = $templateId;
		$this->idsHeldToGrammar = ValidationProfile::fromSource($source)->enforcesBlockIdGrammar();

		// The graph the caller was given, which a name is judged against when the grammar was not asked for one
		// ({@see self::carriesSourceName}). Dropped per call: one instance of this service converts many graphs.
		$this->sourceDraftId = $draftId;
		$this->sourceNames = null;

		return new Draft(
			draftId: $draftId,
			templateId: $templateId,
			userId: $userId,
			blocks: $this->makeBlocks($blocks, $connections, $documentType, $source, $boundBlocks),
			connections: $this->makeConnections($connections),
		);
	}

	private function makeBlocks(
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		array $documentType = [],
		RequestSource $source = RequestSource::Marta,
		array $boundBlocks = [],
	): BlockCollection
	{
		$iconMap = $this->metadataResolver->getIconMap();
		$positions = (new TopologyLayoutEngine())->compute($blocks, $connections);
		$blocksById = $this->indexById($blocks);
		$collection = new BlockCollection();

		foreach ($blocks as $block)
		{
			$pos = $positions[$block->id] ?? ['x' => self::START_POSITION_X, 'y' => self::START_POSITION_Y];

			// Multi-preset activities carry their name/icon/color on the preset (key type_presetId), so a
			// block with a preset resolves its visuals from that entry; anything else uses the base type.
			// A preset the catalog does not know stays unapplied: writing it onto the activity would hand the
			// agent back a preset its own validator rejects on the next send.
			$iconKey = mb_strtolower($block->type);
			$presetId = null;
			if ($block->presetId !== null && $block->presetId !== '' && isset($iconMap[$iconKey . '_' . $block->presetId]))
			{
				$iconKey .= '_' . $block->presetId;
				$presetId = $block->presetId;
			}
			$iconEntry = $iconMap[$iconKey] ?? [];
			$systemName = (string)($iconEntry['name'] ?? '');
			$nodeType = $this->makeNodeType($block->type, $block->presetId, $source);
			$activityId = $this->makeActivityId($block->id);
			$activityType = $iconEntry['className'] ?? $block->type;

			// A frame overlay carries no ports/dialog; its geometry is the bounding box of the members it
			// groups (server-computed), so it is assembled by a dedicated branch rather than the activity path.
			if ($nodeType === NodeType::Frame)
			{
				$collection->add(
					$this->makeFrameBlock($block, $activityId, (string)$activityType, $systemName, $iconEntry, $positions, $blocksById),
				);

				continue;
			}

			$isComplex = $nodeType === NodeType::Complex && $block->rules !== null;
			$dimensions = $this->resolveBlockDimensions($block, $activityId, $boundBlocks);

			$collection->add(
				new Block(
					id: $activityId,
					type: $nodeType,
					x: $pos['x'],
					y: $pos['y'],
					width: $dimensions['width'],
					height: $dimensions['height'],
					title: $systemName,
					icon: $iconEntry['icon'] ?? '',
					ports: $isComplex ? $this->makeComplexPorts($block) : $this->makePortsByBlock($block),
					activityData: $isComplex
						? $this->makeComplexActivityData($block, $activityId, $activityType, $systemName, $documentType)
						: new ActivityData(
							name: $activityId,
							type: $activityType,
							properties: $this->makeActivityProperties($block, $systemName, $activityId, $activityType, $documentType),
							// The preset is a first-class field of a saved activity (the manual editor stamps it
							// when a node is created from the catalog), so it is persisted rather than left to be
							// guessed back from Document: presets that apply no Document (DYNAMIC,
							// AUTOMATED_SOLUTION, ...) are otherwise unrecoverable on read.
							presetId: $presetId,
						),
					colorIndex: isset($iconEntry['colorIndex']) ? (int)$iconEntry['colorIndex'] : null,
					contentBlockColor: isset($iconEntry['contentBlockColor']) ? (int)$iconEntry['contentBlockColor'] : null,
				),
			);
		}

		return $collection;
	}

	/**
	 * Builds a frame overlay block (NodeType::Frame). Its geometry is the bounding box of the members it
	 * groups plus a fixed padding (ALG-02, {@see FrameGeometry}); it carries no ports (an explicit empty
	 * collection, so the port fallback never synthesises i0/o0), keeps the top-level frame type the frontend
	 * needs for z-order, and its node.frame* styling layers the agent overrides over the shared defaults
	 * ({@see FrameData}). The membership itself is a validated logical set - only the geometry is derived here.
	 */
	private function makeFrameBlock(
		AgentBlock $block,
		string $activityId,
		string $activityType,
		string $systemName,
		array $iconEntry,
		array $positions,
		array $blocksById,
	): Block
	{
		$rect = FrameGeometry::boundingBox($this->collectMemberRects($block->memberBlockIds, $positions, $blocksById));
		$title = $block->title !== '' ? $block->title : $systemName;

		return new Block(
			id: $activityId,
			type: NodeType::Frame,
			x: $rect['x'],
			y: $rect['y'],
			width: $rect['width'],
			height: $rect['height'],
			title: $title,
			icon: (string)($iconEntry['icon'] ?? ''),
			ports: new PortCollection(),
			activityData: new ActivityData(
				name: $activityId,
				type: $activityType,
				properties: $title !== '' ? [self::TITLE_PROPERTY_NAME => $title] : [],
			),
			colorIndex: isset($iconEntry['colorIndex']) ? (int)$iconEntry['colorIndex'] : null,
			contentBlockColor: isset($iconEntry['contentBlockColor']) ? (int)$iconEntry['contentBlockColor'] : null,
			frameData: new FrameData(
				colorName: $block->frameColorName,
				content: $block->frameContent,
			),
		);
	}

	/**
	 * Member rectangles in TopologyLayoutEngine space: each member's top-left position sized by its real
	 * widget type ({@see AgentBlockGeometry::measure}), so the frame bounding box matches the on-canvas render.
	 * The layout is keyed by the raw agent block id (the same key the members reference), NOT by the converted
	 * activity id, so member ids must not be passed through makeActivityId() here. Members with an unknown
	 * position or block are skipped.
	 *
	 * @param list<string> $memberBlockIds
	 * @param array<string, array{x: int, y: int}> $positions
	 * @param array<string, AgentBlock> $blocksById
	 * @return list<array{x: int, y: int, width: int, height: int}>
	 */
	private function collectMemberRects(array $memberBlockIds, array $positions, array $blocksById): array
	{
		$memberRects = [];
		foreach ($memberBlockIds as $memberId)
		{
			if (!isset($positions[$memberId], $blocksById[$memberId]))
			{
				continue;
			}

			$size = AgentBlockGeometry::measure($blocksById[$memberId], $this->getActivityRegistry());
			$memberRects[] = [
				'x' => $positions[$memberId]['x'],
				'y' => $positions[$memberId]['y'],
				'width' => $size['width'],
				'height' => $size['height'],
			];
		}

		return $memberRects;
	}

	/**
	 * Indexes the agent blocks by their raw id, so a frame can size its members by type.
	 *
	 * @return array<string, AgentBlock>
	 */
	private function indexById(AgentBlockCollection $blocks): array
	{
		$index = [];
		foreach ($blocks as $block)
		{
			$index[$block->id] = $block;
		}

		return $index;
	}

	/**
	 * Builds the internal connections, preserving the explicit ports supplied by the agent.
	 *
	 * Loop-back topology is carried through here without special-casing via an explicit targetPortId, so a
	 * back edge is never collapsed into the default entry input. The two loop nodes differ in shape:
	 * - ForEach has a dedicated loop-back input i1 (title "->>"); the body tail closes back into i1,
	 *   while the ordinary entry input is i0.
	 * - While has no separate loop-back input: its loop-back is expressed on the output side (o0, title
	 *   "->>", into the body), and the body tail closes back into the ordinary entry input i0.
	 * The o0/i0 defaults apply only to non-loop connections that omit a port; loop connections always
	 * carry their ports explicitly.
	 */
	private function makeConnections(AgentConnectionCollection $connections): ConnectionCollection
	{
		$collection = new ConnectionCollection();
		foreach ($connections as $connection)
		{
			$sourceBlockId = $this->makeActivityId($connection->sourceBlockId);
			$targetBlockId = $this->makeActivityId($connection->destinationBlockId);
			$sourcePortId = $connection->sourcePortId ?? AgentPortDefault::SOURCE_PORT_ID;
			$targetPortId = $connection->targetPortId ?? AgentPortDefault::TARGET_PORT_ID;
			$collection->add(
				new Connection(
					id: "{$sourceBlockId}_{$sourcePortId}_{$targetBlockId}_{$targetPortId}",
					sourceBlockId: $sourceBlockId,
					sourcePortId: $sourcePortId,
					targetBlockId: $targetBlockId,
					targetPortId: $targetPortId,
				),
			);
		}

		return $collection;
	}

	private function makePortsByBlock(AgentBlock $block): PortCollection
	{
		$ports = new PortCollection();
		$isTrigger = $this->makeNodeType($block->type, $block->presetId) === NodeType::Trigger;

		foreach ($this->getNodeTopology($block)->ports?->toArray() ?? [] as $port)
		{
			$direction = PortDirection::tryFrom((string)($port['type'] ?? ''));
			if ($direction === null)
			{
				continue;
			}

			if ($isTrigger && $direction !== PortDirection::Output)
			{
				continue;
			}

			$title = (string)($port['title'] ?? '');
			$ports->add(
				new Port(
					id: (string)($port['id'] ?? ''),
					direction: $direction,
					position: self::PORT_POSITION,
					title: $title !== '' ? $title : null,
				),
			);
		}

		return $ports;
	}

	/**
	 * @param array<string, array{
	 *     type: string,
	 *     presetId: string|null,
	 *     dimensions: array{width: int|null, height: int|null}
	 * }> $boundBlocks
	 * @return array{width: int|null, height: int|null}
	 */
	private function resolveBlockDimensions(AgentBlock $block, string $activityId, array $boundBlocks): array
	{
		$prev = $boundBlocks[$activityId] ?? null;
		if (
			$prev !== null
			&& $this->canonicalActivityCode((string)$prev['type']) === $this->canonicalActivityCode($block->type)
			&& $this->normalizePresetId($prev['presetId'] ?? null) === $this->normalizePresetId($block->presetId)
		)
		{
			return $prev['dimensions'];
		}

		$topology = $this->getNodeTopology($block);

		return ['width' => $topology->width, 'height' => $topology->height];
	}

	private function canonicalActivityCode(string $activityType): string
	{
		return $this->getSearcher()->normalizeActivityCode($activityType);
	}

	private function getNodeTopology(AgentBlock $block): NodeSettings
	{
		return $this->getNodeTopologyResolver()->resolveNodeSettings(
			$this->describeActivity($block->type, $block->presetId),
		);
	}

	private function describeActivity(string $activityType, ?string $presetId): ?ActivityDescription
	{
		return $this->getNodeTopologyResolver()->describe($activityType, $presetId);
	}

	private function getNodeTopologyResolver(): NodeTopologyResolver
	{
		return $this->nodeTopologyResolver ??= new NodeTopologyResolver();
	}

	private function getActivityRegistry(): ActivityRegistry
	{
		return $this->activityRegistry ??= new ActivityRegistry();
	}

	private function getComplexActivityService(): ComplexActivityService
	{
		return $this->complexActivityService ??= BizprocContainer::instance()->getComplexActivityService();
	}

	private function getSearcher(): Searcher
	{
		return $this->searcher ??= BizprocContainer::instance()->getActivitySearcherService();
	}

	private function getNameGeneratorService(): ActivityNameGeneratorService
	{
		return $this->nameGeneratorService ??= ServiceLocator::getInstance()->get('bizproc.service.activity.nameGenerator');
	}

	/**
	 * Ports of a complex node are dynamic: input ports are the rule-projection keys, output ports are
	 * the ids declared by `output` constructions (never the static NODE_SETTINGS.ports of the wrapper).
	 */
	private function makeComplexPorts(AgentBlock $block): PortCollection
	{
		$ports = new PortCollection();
		if ($block->rules === null)
		{
			return $ports;
		}

		$inputNumber = 0;
		foreach ($block->rules->getInputPortIds() as $portId)
		{
			$ports->add(new Port(
				id: $portId,
				direction: PortDirection::Input,
				position: self::PORT_POSITION,
				title: self::COMPLEX_PORT_LABEL_INPUT . (++$inputNumber),
			));
		}

		$outputNumber = 0;
		foreach ($block->rules->getOutputPortIds() as $portId)
		{
			$ports->add(new Port(
				id: $portId,
				direction: PortDirection::Output,
				position: self::PORT_POSITION,
				title: self::COMPLEX_PORT_LABEL_OUTPUT . (++$outputNumber),
			));
		}

		return $ports;
	}

	/**
	 * Builds the complex node's sub-graph by delegating to the rule-engine ({@see ConvertRuleCommand}),
	 * the single source of truth also used by the manual editor - the AI pipeline never assembles the
	 * sub-graph itself (ADR invariant). The agent projection (DTO-01) is mapped to the domain rule
	 * dictionary (presets applied via {@see ComplexActivityService}) and stored on the host as the
	 * canonical `Rules` property; the command produces Children/Links/InputNames/OutputNames (and, for
	 * filters, the host FilterSettings/FilterReturnPropertiesMap/ReturnProperties).
	 *
	 * The rule engine keeps the edited template's document type, the context the manual editor saves rules in
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::saveSettingsAction}), while the
	 * sub-actions themselves are assembled in the container's effective one
	 * ({@see self::resolveSubActionDocumentType}).
	 */
	private function makeComplexActivityData(
		AgentBlock $block,
		string $activityId,
		string $activityType,
		string $systemName,
		array $documentType,
	): ActivityData
	{
		$portRuleDtoDictionary = $this->buildPortRuleDtoDictionary(
			$block,
			$this->resolveSubActionDocumentType($activityType, $documentType),
		);

		$properties = $this->applyNodeNaming(
			[
				$this->resolveRulePropertyName($activityType) => $this->serializePortRuleDictionary($portRuleDtoDictionary),
			],
			$block->title,
			$block->description,
			$systemName,
		);

		$hostActivity = new ActivityData(
			name: $activityId,
			type: $activityType,
			properties: $properties,
		);

		$portCount = count($portRuleDtoDictionary);
		$ruleCount = $this->countRules($portRuleDtoDictionary);

		try
		{
			$result = (new ConvertRuleCommand(
				activity: $hostActivity,
				portRuleDtoDictionary: $portRuleDtoDictionary,
				documentType: $documentType,
			))->run();

			if ($result instanceof ConvertRuleCommandResult)
			{
				$this->logComplexSubGraphMaterialization(
					$activityType,
					$portCount,
					$ruleCount,
					self::COMPLEX_OUTCOME_MATERIALIZED,
				);

				return $result->activityData;
			}

			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				$this->formatErrorReasons($result->getErrors()),
			);
		}
		catch (CommandValidateException $exception)
		{
			// The rule-engine rejected the projection the same way the manual editor's ConvertRuleCommand
			// would - record the reasons so a divergence between the agent's assembly and the editor is
			// discoverable.
			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				$this->formatErrorReasons($exception->errors),
			);
		}
		catch (\Throwable $exception)
		{
			$this->logComplexSubGraphMaterialization(
				$activityType,
				$portCount,
				$ruleCount,
				self::COMPLEX_OUTCOME_FALLBACK,
				[$exception->getMessage()],
			);
		}

		// Isolate a rule-engine failure: keep the block with its canonical Rules property instead of
		// dropping the whole draft (failure isolation, as in the catalog listing) - the diagnostic marker
		// above never changes this outcome.
		return $hostActivity;
	}

	/**
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 */
	private function countRules(array $portRuleDtoDictionary): int
	{
		$count = 0;
		foreach ($portRuleDtoDictionary as $portRule)
		{
			$count += count($portRule->rules);
		}

		return $count;
	}

	/**
	 * Emits a diagnostic marker for a complex node's sub-graph materialization on the same channel the
	 * catalog uses for skipped blocks ({@see BlockDescriptionService::logSkippedBlock}) - no new channel.
	 * It records the host type, the port/rule counts and the outcome; on a fallback it attaches the
	 * rule-engine reasons, so a divergence between the agent's assembly and the manual editor is
	 * discoverable. Purely observational: it never changes the assembled block nor drops the draft
	 * (failure isolation, as in the catalog listing).
	 *
	 * @param list<string> $reasons
	 */
	private function logComplexSubGraphMaterialization(
		string $activityType,
		int $portCount,
		int $ruleCount,
		string $outcome,
		array $reasons = [],
	): void
	{
		$context = [
			'activity' => $activityType,
			'ports' => $portCount,
			'rules' => $ruleCount,
			'outcome' => $outcome,
		];
		if ($reasons !== [])
		{
			$context['reasons'] = $reasons;
		}

		$message = 'AI complex node: sub-graph materialization';
		if ($outcome === self::COMPLEX_OUTCOME_FALLBACK)
		{
			$this->getLogger()->warning($message, $context);
		}
		else
		{
			$this->getLogger()->info($message, $context);
		}
	}

	/**
	 * @param Error[] $errors
	 * @return list<string>
	 */
	private function formatErrorReasons(array $errors): array
	{
		$reasons = [];
		foreach ($errors as $error)
		{
			if (!$error instanceof Error)
			{
				continue;
			}

			$code = (string)$error->getCode();
			$reasons[] = ($code !== '' && $code !== '0' ? $code . ': ' : '') . $error->getMessage();
		}

		return $reasons;
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}

	/**
	 * Document type a container's sub-actions are described and asked under: the container's fixed document
	 * when it locks one, the edited template's otherwise - the effective type
	 * ({@see \Bitrix\BizprocDesigner\Internal\Service\Activity\NodeFilterAvailability::resolveEffectiveDocumentType}).
	 *
	 * The catalog builds the sub-action settings schema in exactly this context
	 * ({@see ComplexBlockDetailFactory::build}) and the name/value cross-check judges the agent's settings
	 * against that schema, so the declarations this path reads for the same sub-action - the enforced values,
	 * the passthrough names and the settings dialog - have to be asked in it too. Otherwise a container with a
	 * fixed document would publish its schema in one context and apply the declarations in another.
	 */
	private function resolveSubActionDocumentType(string $activityType, array $documentType): array
	{
		try
		{
			$fixedDocumentType = $this->getComplexActivityService()->getFixedDocumentTypeForNodeAction($activityType);

			return Container::getNodeFilterAvailability()
				->resolveEffectiveDocumentType($fixedDocumentType, $documentType)
				?? $documentType
			;
		}
		catch (\Throwable)
		{
			// Failure isolation, as everywhere on this path: a container whose fixed document cannot be read
			// keeps the edited template's type instead of dropping the block out of the draft.
			return $documentType;
		}
	}

	/**
	 * Maps the nested agent projection (DTO-01) onto the domain rule dictionary (map<portId, PortRuleDto>)
	 * consumed by {@see ConvertRuleCommand}. This is the symmetric counterpart of the manual editor's
	 * `extractRulePropertyValue`, so the same command produces an identical sub-graph.
	 *
	 * @param array $subActionDocumentType effective document type of the container ({@see self::resolveSubActionDocumentType})
	 * @return array<string, PortRuleDto>
	 */
	private function buildPortRuleDtoDictionary(AgentBlock $block, array $subActionDocumentType): array
	{
		$dictionary = [];
		foreach ($block->rules->portRules as $portId => $rules)
		{
			$ruleDtos = [];
			foreach ($rules as $rule)
			{
				$ruleDtos[] = new RuleDto(
					$rule->id,
					$this->buildRuleConstructions($rule, $block, $subActionDocumentType),
				);
			}

			$dictionary[$portId] = new PortRuleDto($portId, $ruleDtos);
		}

		return $dictionary;
	}

	/**
	 * @return list<ConstructionDto>
	 */
	private function buildRuleConstructions(
		AgentComplexRule $rule,
		AgentBlock $block,
		array $subActionDocumentType,
	): array
	{
		$constructions = [];
		$previousWasCondition = false;

		foreach ($rule->constructions as $construction)
		{
			$expression = $construction->expression;

			$constructions[] = match ($construction->type)
			{
				AgentComplexConstruction::TYPE_CONDITION => new ConstructionDto(
					$this->generateConstructionId(),
					$this->resolveConditionConstructionType($expression, $previousWasCondition),
					$this->mapConditionExpression($expression),
				),
				AgentComplexConstruction::TYPE_ACTION => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::ACTION,
					$this->mapActionExpression($expression, $block, $subActionDocumentType),
				),
				AgentComplexConstruction::TYPE_FILTER => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::FILTER,
					$this->mapFilterExpression($expression, $subActionDocumentType),
				),
				AgentComplexConstruction::TYPE_OUTPUT => new ConstructionDto(
					$this->generateConstructionId(),
					ConstructionType::OUTPUT,
					$this->mapOutputExpression($expression),
				),
				default => null,
			};

			$previousWasCondition = $construction->type === AgentComplexConstruction::TYPE_CONDITION;
		}

		return array_values(array_filter($constructions));
	}

	/**
	 * Expands the DNF joiner of the agent condition contract (DTO-03) back into the domain construction
	 * type: the first condition of a contiguous run is the `if`, later conditions are `and`/`or` by
	 * joiner (`OR` opens a new group). The joiner of the first condition is irrelevant - a group's first
	 * item is always treated as AND, which is exactly what `condition:if` yields downstream.
	 */
	private function resolveConditionConstructionType(array $expression, bool $previousWasCondition): ConstructionType
	{
		if (!$previousWasCondition)
		{
			return ConstructionType::IF_CONDITION;
		}

		return ($expression['joiner'] ?? 'AND') === 'OR'
			? ConstructionType::OR_CONDITION
			: ConstructionType::AND_CONDITION
		;
	}

	private function mapConditionExpression(array $expression): ConditionExpressionDto
	{
		$field = $expression['field'] ?? null;

		return new ConditionExpressionDto(
			field: is_array($field) ? FieldDto::createFromArray($field) : null,
			operator: isset($expression['operator']) ? (string)$expression['operator'] : null,
			value: $expression['value'] ?? null,
		);
	}

	private function mapOutputExpression(array $expression): OutputExpressionDto
	{
		return new OutputExpressionDto(
			isset($expression['portId']) ? (string)$expression['portId'] : null,
			isset($expression['title']) ? (string)$expression['title'] : null,
		);
	}

	/**
	 * Builds the sub-action child activity data from the agent action ({activityCode, settings, document}),
	 * applying the node-action preset via {@see ComplexActivityService} (the same source as the manual
	 * editor's palette): the resolved activity class becomes the child Type, the preset title/id are
	 * stamped, and the agent settings fill the properties. Presets are mandatory - a naive child would
	 * diverge from the editor.
	 *
	 * The values the sub-action's dialog derives on save are then synthesised the same way the flat block
	 * gets them ({@see self::applySubActionAdditionalResult}), so a sub-action the agent assembled carries
	 * what one saved by hand would.
	 *
	 * @param array $subActionDocumentType the container's effective document type, the context its sub-actions
	 *        are described and cross-checked in ({@see self::resolveSubActionDocumentType})
	 */
	private function mapActionExpression(
		array $expression,
		AgentBlock $block,
		array $subActionDocumentType,
	): ActionExpressionDto
	{
		$activityCode = isset($expression['activityCode']) ? (string)$expression['activityCode'] : '';
		// `document` binds the sub-action to a document via a bizproc expression `{=<sourceBlockId>:<propertyId>}`
		// (e.g. `{=<triggerId>:ReturnDocument}`); downstream ({@see ConvertRuleCommand::resolveTargetFilterId},
		// the runtime `setDocumentContext(string)`) parses/consumes it as a string. Accept only a non-empty string
		// here - a non-string (e.g. a document-type triplet array, an invalid input) is dropped to null instead of
		// being blindly `(string)`-cast into `"Array"`. Such invalid input is already rejected by
		// {@see AgentComplexRulesValidator::validateAction} before reaching the converter (both the REST and Marta paths).
		$document = (isset($expression['document']) && is_string($expression['document']) && $expression['document'] !== '')
			? $expression['document']
			: null;

		// Optional aux branch of a sub-action (manual-editor feature the reverse path carries back). Restored
		// verbatim so a round-trip of a hand-built template does not drop it; the rule-engine materialises
		// `auxPortId` into the child `Properties.auxPort`. AI-generated sub-actions never carry it (BC).
		$auxPortId = isset($expression['auxPortId']) && $expression['auxPortId'] !== ''
			? (string)$expression['auxPortId']
			: null;
		$auxPortTitle = isset($expression['auxPortTitle']) && $expression['auxPortTitle'] !== ''
			? (string)$expression['auxPortTitle']
			: null;

		$nodeAction = $this->resolveNodeActionDescription($block->type, $activityCode);

		// Canonical node-action class (PascalCase) - the same value the manual editor keys its `actions`
		// dictionary by ({@see Complex::loadSettingsAction}). Reused for both the child Type and the
		// construction actionId so the settings dialog's `actions.get(actionId)` resolves. Falls back to the
		// raw agent code only when the node-action is unresolved (an out-of-dictionary code the validator owns).
		$actionClass = $nodeAction?->getClass() ?? $activityCode;

		$settings = $this->normalizeSettings($expression['settings'] ?? null);

		// The name is stamped AFTER the values the activity enforces, the order every manual save uses: the
		// dialog writes the enforced values first and the title goes over them
		// ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler},
		// {@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\SaveSingleRuleCommand}), which is also
		// how the flat block is laid out ({@see self::makeActivityProperties}). The sub-action's own name wins
		// over an enforced one; absent it, the node-action preset's name is the default.
		$title = array_key_exists(self::TITLE_PROPERTY_NAME, $settings)
			? $settings[self::TITLE_PROPERTY_NAME]
			: ($nodeAction?->getName() ?? '')
		;

		$properties = $this->applyEnforcedSettingValues($settings, $actionClass, $subActionDocumentType);
		$properties[self::TITLE_PROPERTY_NAME] = $title;

		$childName = $this->generateChildName();
		$properties = $this->applySubActionAdditionalResult(
			$properties,
			$childName,
			$actionClass,
			$subActionDocumentType,
		);

		$activityData = [
			'Name' => $childName,
			'Type' => $actionClass,
			'Activated' => 'Y',
			'Properties' => $properties,
			'ReturnProperties' => [],
			'Document' => $document,
		];

		$presetId = $nodeAction?->get('PRESET_ID');
		if (is_string($presetId) && $presetId !== '')
		{
			$activityData['PresetId'] = $presetId;
		}

		return new ActionExpressionDto(
			actionId: $actionClass,
			rawActivityData: null,
			activityData: $activityData,
			document: $document,
			auxPortId: $auxPortId,
			auxPortTitle: $auxPortTitle,
		);
	}

	/**
	 * Builds the filter backing activity data from the agent filter ({activityCode, settings, filterId?}). The
	 * rule-engine serialises it into the host FilterSettings/FilterReturnPropertiesMap rather than into a
	 * child (see {@see ConvertRuleCommand}); the AI pipeline only carries the backing data through.
	 *
	 * The backing activity `Name` is the filter's stable identity: {@see ConvertRuleCommand::extractFilterSettings}
	 * derives `filterId` from it and consumers reference the filter as `{=<filterId>:Document}`
	 * (`TargetFilterId`). A `filterId` supplied by the reverse projection is reused verbatim so the identity
	 * survives a round-trip and those references keep resolving; a genuinely new filter (no id) still gets a
	 * freshly generated name.
	 *
	 * Of the two declarations the other write paths read, only one is applied here: the values the backing activity
	 * enforces, as on the flat block and the sub-action. The derived ADDITIONAL_RESULT descriptors those paths
	 * synthesise ({@see self::applyAdditionalResult}) are not - no filter activity declares such a key today, so
	 * this path has nothing to synthesise, and one that starts to has to be brought onto the synthesis deliberately
	 * rather than being covered by a symmetry the code does not have.
	 *
	 * @param array $documentType the container's effective document type ({@see self::resolveSubActionDocumentType})
	 */
	private function mapFilterExpression(array $expression, array $documentType): ActionExpressionDto
	{
		$activityCode = isset($expression['activityCode']) ? (string)$expression['activityCode'] : '';
		$settings = $this->applyEnforcedSettingValues(
			$this->normalizeSettings($expression['settings'] ?? null),
			$activityCode,
			$documentType,
		);

		$filterName = isset($expression['filterId']) && is_string($expression['filterId']) && $expression['filterId'] !== ''
			? $expression['filterId']
			: $this->generateChildName();

		$activityData = [
			'Name' => $filterName,
			'Type' => $activityCode,
			'Activated' => 'Y',
			'Properties' => $settings,
			'ReturnProperties' => is_array($settings['ReturnProperties'] ?? null) ? $settings['ReturnProperties'] : [],
		];

		return new ActionExpressionDto(
			actionId: $activityCode,
			rawActivityData: null,
			activityData: $activityData,
			document: null,
		);
	}

	/**
	 * Resolves a preset-applied node-action description for the block's complex node, matched by
	 * normalized activity code against the same dictionary the manual editor uses. Cached per block type.
	 */
	private function resolveNodeActionDescription(string $blockType, string $activityCode): ?ActivityDescription
	{
		$normalizedCode = $this->getSearcher()->normalizeActivityCode($activityCode);
		if ($normalizedCode === '')
		{
			return null;
		}

		$blockKey = mb_strtolower($blockType);
		if (!array_key_exists($blockKey, $this->nodeActionDescriptionCache))
		{
			$descriptions = [];
			foreach ($this->getComplexActivityService()->getCorrespondingNodeActionActivityByName($blockKey) as $description)
			{
				/** @var ActivityDescription $description */
				$descriptions[$this->getSearcher()->normalizeActivityCode((string)$description->getClass())] = $description;
			}

			$this->nodeActionDescriptionCache[$blockKey] = $descriptions;
		}

		return $this->nodeActionDescriptionCache[$blockKey][$normalizedCode] ?? null;
	}

	/**
	 * Serialises the domain rule dictionary into the canonical `Rules` property shape (map<portId, {portId,
	 * ruleCards}>) - the same form the manual editor persists and the reverse converter reads back.
	 *
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 */
	private function serializePortRuleDictionary(array $portRuleDtoDictionary): array
	{
		$encoded = json_encode($portRuleDtoDictionary, JSON_UNESCAPED_UNICODE);
		$decoded = $encoded === false ? null : json_decode($encoded, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Rule property name of a node, resolved by name through {@see ComplexActivityService::resolveRulePropertyName()}:
	 * the properties map carries more than one property of type {@see FieldType::RULES}, so its order must not
	 * decide where the rules are written. An activity that does not resolve here keeps the canonical name.
	 */
	private function resolveRulePropertyName(string $activityType): string
	{
		try
		{
			$configurator = \CBPActivity::createConfigurator($activityType, []);
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

	private function normalizeSettings(mixed $settings): array
	{
		return is_array($settings) ? $settings : [];
	}

	private function generateChildName(): string
	{
		return $this->getNameGeneratorService()->generate();
	}

	private function generateConstructionId(): string
	{
		return Random::getStringByAlphabet(16, Random::ALPHABET_ALPHALOWER | Random::ALPHABET_NUM);
	}

	/**
	 * Lays out block properties, keeping the user-facing overrides separate from the system node name.
	 *
	 * Title/EditorComment mirror the manual node editor: an override is written only when it carries
	 * information beyond the system name. An empty title, or a title equal (whitespace-insensitive) to the
	 * system name, adds no Properties.Title; an empty/absent description adds no Properties.EditorComment.
	 *
	 * The user-facing Title/EditorComment are laid out AFTER the settings, so a setting that happens to be
	 * named "Title"/"EditorComment" can never overwrite the block's name/description. The values the activity
	 * enforces are applied to the settings in between, so they cannot touch the name/description either.
	 * Finally the computed ADDITIONAL_RESULT descriptors (e.g. a CRM trigger's `Return`) are synthesised via
	 * applyAdditionalResult.
	 */
	private function makeActivityProperties(
		AgentBlock $block,
		string $systemName,
		string $activityId,
		string $activityType,
		array $documentType,
	): array
	{
		$map = [];

		foreach ($block->settings as $setting)
		{
			$map[$setting->name] = $setting->value;
		}

		$map = $this->applyEnforcedSettingValues($map, $activityType, $documentType);
		$map = $this->applyServerOwnedProperties($map, $activityId, $activityType);
		$map = $this->applyNodeNaming($map, $block->title, $block->description, $systemName);

		return $this->applyAdditionalResult($map, $activityId, $activityType, $documentType);
	}

	/**
	 * The editor's own state that the reverse projection never hands the agent
	 * ({@see AiAssistantWorkflowTemplateConverterService::EDITOR_SERVICE_PROPERTY_NAMES}). Not being handed it,
	 * the agent cannot send it back, so a name dropped on the read side and left alone here is a value lost on
	 * the first agent write: a loop a person folded on the canvas would come back unfolded.
	 *
	 * Dropped from the input first and restored from the published template only, never from what arrived: the
	 * value is the server's whether or not a published one exists, so a forged one stays out either way and a
	 * newly authored block simply has none. The derived descriptors an activity declares through its
	 * `ADDITIONAL_RESULT` marker are not handled here - they reach the agent and come back, and what happens to
	 * them is {@see self::applyAdditionalResult()}.
	 */
	private function applyServerOwnedProperties(array $properties, string $activityId, string $activityType): array
	{
		$names = AiAssistantWorkflowTemplateConverterService::EDITOR_SERVICE_PROPERTY_NAMES;

		foreach ($names as $name)
		{
			unset($properties[$name]);
		}

		return $this->restoreBaselineSettingValues($properties, $activityId, $activityType, $names);
	}

	/**
	 * Applies the setting values the activity declares as enforced ({@see \CBPActivity::getAiEnforcedSettingValues}):
	 * its properties dialog writes them on every save and the agent path never opens that dialog. The declaration
	 * is a name => value map, so the converter applies it without knowing what any of those settings mean.
	 *
	 * A value supplied by the agent is normalised silently rather than refused: a node authored before the dialog
	 * started writing the value (an imported template, an older agent-built one) must still survive the round-trip,
	 * and refusing would make the agent stricter than a person importing the same .bpt. A declaration that cannot
	 * be read leaves the properties untouched ({@see self::getEnforcedSettingValues}).
	 */
	private function applyEnforcedSettingValues(array $properties, string $activityCode, array $documentType): array
	{
		foreach ($this->getEnforcedSettingValues($activityCode, $documentType) as $name => $value)
		{
			$properties[$name] = $value;
		}

		return $properties;
	}

	/**
	 * The activity's enforced name => value declaration, or [] when it declares none. Read the same safe way as its
	 * passthrough twin - the activity file is included first, so callStaticMethod cannot answer with its
	 * error-shaped array - and any failure yields no values. Cached per (code, document type) for the same reason
	 * as the twin: the declaration takes the document type, and a node is asked for it more than once
	 * ({@see self::applyEnforcedSettingValues}, {@see self::resolveDialogOwnedKeys}).
	 *
	 * @return array<string, mixed>
	 */
	private function getEnforcedSettingValues(string $activityCode, array $documentType): array
	{
		$normalizedCode = $this->getSearcher()->normalizeActivityCode($activityCode);
		if ($normalizedCode === '')
		{
			return [];
		}

		$code = $normalizedCode . '|' . implode('/', array_map('strval', $documentType));

		if (!array_key_exists($code, $this->enforcedSettingValuesCache))
		{
			$enforced = null;

			try
			{
				if ($this->getSearcher()->includeActivityFile($activityCode) !== false)
				{
					$enforced = \CBPActivity::callStaticMethod(
						$activityCode,
						'getAiEnforcedSettingValues',
						[$documentType],
					);
				}
			}
			catch (\Throwable)
			{
			}

			$values = [];
			foreach (is_array($enforced) ? $enforced : [] as $name => $value)
			{
				if (is_string($name))
				{
					$values[$name] = $value;
				}
			}

			$this->enforcedSettingValuesCache[$code] = $values;
		}

		return $this->enforcedSettingValuesCache[$code];
	}

	/**
	 * Synthesises the computed ADDITIONAL_RESULT descriptors (e.g. a CRM trigger's `Return`) through the same
	 * domain entry the manual editor uses ({@see \Bitrix\BizprocDesigner\Public\Command\Activity\Settings\SaveCommandHandler}):
	 * `getPropertiesDialogValues` recomputes them from the activity's own settings and writes them onto the
	 * activity node. The AI/REST path otherwise maps settings flatly and never runs the dialog, so a downstream
	 * CRM block reading `Return.ReturnDocument` would find it empty until the user re-saved the trigger by hand.
	 *
	 * Only carriers of the marker are processed - its `currentValues` shape and document context are
	 * trigger-specific, so running the dialog for every activity would be wrong. Only the freshly computed
	 * marker keys are lifted back onto the agent-provided settings: `getPropertiesDialogValues` overwrites the
	 * activity `Properties` wholesale, so the service properties the agent set (Title, Name and the flat
	 * settings) are preserved here rather than re-derived (contract of {@see SaveCommandHandler}). A dialog
	 * failure (required Document/Fields missing -> false/errors) leaves the settings untouched instead of
	 * dropping the block: the descriptor is best-effort, the push never fails.
	 *
	 * A marker key the activity also declares as passthrough is the dialog's on this path too, exactly as on the
	 * sub-action ({@see self::applySubActionAdditionalResult}). The reverse projection emits such a key, so the
	 * agent can send one back and the name check lets it by - but its value is computed from other settings, not
	 * configured. What the agent sent is therefore dropped before the dialog runs, so a descriptor the dialog does
	 * not compute (an absent schema) leaves no agent-authored value behind. Keys the agent cannot name in the first
	 * place (`Return` and the like, excluded from the projection) are untouched by this.
	 *
	 * A dialog that refuses altogether is the one case where dropping alone would be wrong: the refusal can come
	 * from the environment rather than from the agent (a saved `engine` that left the portal), and an agent that
	 * returned the node unchanged would then silently lose a value it was handed. So the key is restored from the
	 * published template instead ({@see self::restoreBaselineSettingValues}) - never from the input, so a forged
	 * value stays out either way.
	 */
	private function applyAdditionalResult(
		array $properties,
		string $activityId,
		string $activityType,
		array $documentType,
	): array
	{
		$markerKeys = $this->getAdditionalResultKeys($activityType);
		if ($markerKeys === [])
		{
			return $properties;
		}

		$dialogOwnedKeys = $this->resolveDialogOwnedKeys($markerKeys, $activityType, $documentType);
		foreach ($dialogOwnedKeys as $key)
		{
			unset($properties[$key]);
		}

		$resolved = $this->resolveDialogProperties($activityId, $activityType, $documentType, $properties);
		if ($resolved === null)
		{
			return $this->restoreBaselineSettingValues($properties, $activityId, $activityType, $dialogOwnedKeys);
		}

		foreach ($markerKeys as $key)
		{
			if (is_array($resolved[$key] ?? null))
			{
				$properties[$key] = $resolved[$key];
			}
		}

		return $properties;
	}

	/**
	 * The sub-action counterpart of {@see self::applyAdditionalResult}: the AI node's sub-action is the main
	 * scenario of the agent path, and its dialog also derives a value on save (`jsonPath`, computed from
	 * `jsonSchema`), which the flat mapping never produced - so the JSON schema's virtual fields stayed
	 * invisible to the neighbouring nodes until a person re-saved the node by hand.
	 *
	 * Narrower than the flat block on purpose: only a marker key the activity itself declares as passthrough
	 * ({@see \CBPActivity::getAiPassthroughSettingNames}) is synthesised. A sub-action's properties go back to
	 * the agent as `settings` (the reverse projection strips only `Title`), unlike a flat block's, whose derived
	 * keys the reverse path drops by name. So a marker key the activity has not declared would come back as a
	 * setting no schema knows and the name check would refuse it - the round-trip must stay closed, and the
	 * declaration is what makes it so.
	 *
	 * Because the agent sees those keys, it also re-sends them - and what it sent is dropped before the dialog
	 * runs, exactly as on the flat block ({@see self::applyAdditionalResult}). So the property set follows the
	 * dialog's answer whole instead of being written over the agent's: a node that switched `returnType` back to
	 * `text` stops declaring the virtual fields of a JSON schema it no longer has. A dialog that did not answer at
	 * all falls back to the published template through the same helper as the flat block, which for a sub-action
	 * means no value at all in practice: its child name is generated afresh on every push, so a sub-action has no
	 * identity in the baseline to be looked up by.
	 */
	private function applySubActionAdditionalResult(
		array $properties,
		string $activityId,
		string $activityType,
		array $documentType,
	): array
	{
		$declaredKeys = $this->resolveDialogOwnedKeys(
			$this->getAdditionalResultKeys($activityType),
			$activityType,
			$documentType,
		);
		if ($declaredKeys === [])
		{
			return $properties;
		}

		foreach ($declaredKeys as $key)
		{
			unset($properties[$key]);
		}

		$resolved = $this->resolveDialogProperties($activityId, $activityType, $documentType, $properties);
		if ($resolved === null)
		{
			return $this->restoreBaselineSettingValues($properties, $activityId, $activityType, $declaredKeys);
		}

		foreach ($declaredKeys as $key)
		{
			if (is_array($resolved[$key] ?? null))
			{
				$properties[$key] = $resolved[$key];
			}
		}

		return $properties;
	}

	/**
	 * Marker keys this path owns: the ones the activity declares as passthrough, minus the ones it also enforces.
	 * An enforced key carries a value the activity dictates and {@see self::applyEnforcedSettingValues} has already
	 * written, so it is not the agent's to drop - and the declaration is a scalar, which the array-only lift back
	 * from the dialog would never restore.
	 *
	 * @param list<string> $markerKeys
	 * @return list<string>
	 */
	private function resolveDialogOwnedKeys(array $markerKeys, string $activityType, array $documentType): array
	{
		return array_values(array_diff(
			array_intersect($markerKeys, $this->getPassthroughSettingNames($activityType, $documentType)),
			array_keys($this->getEnforcedSettingValues($activityType, $documentType)),
		));
	}

	/**
	 * Restores the listed settings from the published template of the bound template id, for a node whose dialog
	 * refused to answer. The published state is the only value on this path the agent did not author: the draft is
	 * what the agent itself writes, so reading it back would restore the agent's own input. A node absent from the
	 * baseline (a newly authored one) keeps no value, which is what the refused dialog leaves behind anyway - and so
	 * is a baseline node of another type ({@see self::getBaselineProperties}).
	 *
	 * @param list<string> $keys
	 */
	private function restoreBaselineSettingValues(
		array $properties,
		string $activityId,
		string $activityType,
		array $keys,
	): array
	{
		if ($keys === [])
		{
			return $properties;
		}

		$baseline = $this->getBaselineProperties($activityId, $activityType);
		foreach ($keys as $key)
		{
			if (array_key_exists($key, $baseline))
			{
				$properties[$key] = $baseline[$key];
			}
		}

		return $properties;
	}

	/**
	 * Properties the published template holds for the node of this name AND this type, or [] when there is no bound
	 * template, no published state, no such node in it, or a node of another type carries that name.
	 *
	 * The name alone would not identify the node: it comes from the agent ({@see self::makeActivityId}) and nothing
	 * on the write path ties it to a type, so a node named after a neighbour of another type - one that declares the
	 * same derived key - would otherwise be handed that neighbour's value by a refusal the agent can cause itself.
	 * The same guard covers the honest case of a reused id.
	 *
	 * @return array<string, mixed>
	 */
	private function getBaselineProperties(string $activityId, string $activityType): array
	{
		$type = $this->getSearcher()->normalizeActivityCode($activityType);
		if ($type === '')
		{
			return [];
		}

		$activity = $this->getBaselineActivities()[$activityId] ?? null;

		return $activity !== null && $activity['Type'] === $type ? $activity['Properties'] : [];
	}

	/**
	 * The published template flattened to `name => {normalized type, properties}`, built at most once per id and
	 * read by the value restore and by {@see self::readSourceNames}. Walks the tree by the same rule as the
	 * by-name lookup of the dialog entry
	 * ({@see \CBPWorkflowTemplateLoader::findActivityByName}): depth-first, a node before its children, the first
	 * node of a name winning - so a nested node is found exactly as a top-level one. Flattening it once is what
	 * spares that lookup, which takes the template by reference, a copy of the tree per node: a dialog refuses for
	 * environmental reasons (an engine that left the portal) for every node of that kind at once.
	 *
	 * @return array<string, array{Type: string, Properties: array}>
	 */
	private function getBaselineActivities(): array
	{
		if ($this->baselineTemplateId <= 0)
		{
			return [];
		}

		if (!array_key_exists($this->baselineTemplateId, $this->baselineActivitiesById))
		{
			$activities = [];
			$this->indexBaselineActivities($this->getBaselineTemplate() ?? [], $activities);
			$this->baselineActivitiesById[$this->baselineTemplateId] = $activities;
		}

		return $this->baselineActivitiesById[$this->baselineTemplateId];
	}

	/**
	 * @param array<string, array{Type: string, Properties: array}> $activities
	 */
	private function indexBaselineActivities(array $template, array &$activities): void
	{
		foreach ($template as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$name = is_scalar($activity['Name'] ?? null) ? (string)$activity['Name'] : '';
			$type = is_scalar($activity['Type'] ?? null) ? (string)$activity['Type'] : '';

			if ($name !== '' && !array_key_exists($name, $activities))
			{
				$activities[$name] = [
					'Type' => $this->getSearcher()->normalizeActivityCode($type),
					'Properties' => is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [],
				];
			}

			if (is_array($activity['Children'] ?? null))
			{
				$this->indexBaselineActivities($activity['Children'], $activities);
			}
		}
	}

	/**
	 * The published TEMPLATE of the bound template, read at most once per id and only by the two readers that
	 * cannot answer without it: a refused dialog restoring a derived value, and a name outside the grammar being
	 * told apart from one the source graph carries ({@see self::carriesSourceName}). The second never arises on
	 * the entry held to the grammar, so the external contour still reaches no database here.
	 */
	private function getBaselineTemplate(): ?array
	{
		if ($this->baselineTemplateId <= 0)
		{
			return null;
		}

		if (!array_key_exists($this->baselineTemplateId, $this->baselineTemplateById))
		{
			$this->baselineTemplateById[$this->baselineTemplateId] = $this->loadPublishedTemplate($this->baselineTemplateId);
		}

		return $this->baselineTemplateById[$this->baselineTemplateId];
	}

	private function loadPublishedTemplate(int $templateId): ?array
	{
		try
		{
			$template = WorkflowTemplateTable::query()
				->where('ID', $templateId)
				->setSelect(['ID', 'TEMPLATE'])
				->setLimit(1)
				->fetchObject()
				?->getTemplate()
			;
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($template) ? $template : null;
	}

	/**
	 * Setting names the activity declares as passthrough - persisted properties that are not fields the agent
	 * configures ({@see \Bitrix\Bizproc\Integration\AiAssistant\ActivityAiAutoDescriber} folds the same list into
	 * the description's `skippedSettings`, which is what makes them known names on the way back). Read the same
	 * safe way as the enforced values - the activity file first, any failure yields no names. Cached per
	 * (code, document type): the declaration takes the document type, so caching by code alone would carry one
	 * context's answer into another.
	 *
	 * @return list<string>
	 */
	private function getPassthroughSettingNames(string $activityCode, array $documentType): array
	{
		$normalizedCode = $this->getSearcher()->normalizeActivityCode($activityCode);
		if ($normalizedCode === '')
		{
			return [];
		}

		$code = $normalizedCode . '|' . implode('/', array_map('strval', $documentType));

		if (!array_key_exists($code, $this->passthroughSettingNamesCache))
		{
			$names = null;

			try
			{
				if ($this->getSearcher()->includeActivityFile($activityCode) !== false)
				{
					$names = \CBPActivity::callStaticMethod($activityCode, 'getAiPassthroughSettingNames', [$documentType]);
				}
			}
			catch (\Throwable)
			{
			}

			$this->passthroughSettingNamesCache[$code] = is_array($names)
				? array_values(array_filter($names, 'is_string'))
				: []
			;
		}

		return $this->passthroughSettingNamesCache[$code];
	}

	/**
	 * ADDITIONAL_RESULT marker keys declared by the activity's `.description.php` (e.g. `['Return']` for CRM
	 * triggers), or [] when the activity is not a carrier. Resolved by the same catalog lookup
	 * ({@see Searcher::searchByCode}) that keys the block catalog, on the normalized activity code, and cached
	 * per code.
	 *
	 * @return list<string>
	 */
	private function getAdditionalResultKeys(string $activityType): array
	{
		$code = $this->getSearcher()->normalizeActivityCode($activityType);
		if ($code === '')
		{
			return [];
		}

		if (!array_key_exists($code, $this->additionalResultKeysCache))
		{
			$keys = $this->getSearcher()->searchByCode($code)?->getAdditionalResult();
			$this->additionalResultKeysCache[$code] = is_array($keys)
				? array_values(array_filter($keys, 'is_string'))
				: [];
		}

		return $this->additionalResultKeysCache[$code];
	}

	/**
	 * Runs the activity's `getPropertiesDialogValues` exactly as the manual editor does (same document context,
	 * currentValues and by-ref single-node workflow template), returning the recomputed `Properties` of the
	 * activity, or null when the dialog signals a validation error (false result or non-empty errors) so the
	 * caller isolates the failure. The activity file is included first so the static method resolves.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveDialogProperties(
		string $activityId,
		string $activityType,
		array $documentType,
		array $currentValues,
	): ?array
	{
		$this->getSearcher()->includeActivityFile($activityType);

		if (!method_exists('CBP' . $activityType, 'getPropertiesDialogValues'))
		{
			return null;
		}

		$template = [[
			'Name' => $activityId,
			'Type' => $activityType,
			'Properties' => $currentValues,
		]];
		$parameters = [];
		$variables = [];
		$constants = [];
		$errors = [];

		try
		{
			$result = \CBPActivity::callStaticMethod(
				$activityType,
				'getPropertiesDialogValues',
				[
					$documentType,
					$activityId,
					&$template,
					&$parameters,
					&$variables,
					$currentValues,
					&$errors,
					$constants,
				],
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		if ($result === false || $errors !== [])
		{
			return null;
		}

		$activity = &\CBPWorkflowTemplateLoader::findActivityByName($template, $activityId);

		return is_array($activity['Properties'] ?? null) ? $activity['Properties'] : null;
	}

	private function makeNodeType(
		string $agentBlockType,
		?string $presetId = null,
		RequestSource $source = RequestSource::Marta,
	): NodeType
	{
		if ($source === RequestSource::Rest && FrameBlockMatcher::matches($agentBlockType, $presetId))
		{
			return NodeType::Frame;
		}

		if ($this->getActivityRegistry()->isComplexWrapper($agentBlockType))
		{
			return NodeType::Complex;
		}

		$nodeType = $this->getNodeTopologyResolver()->resolveNodeType(
			$this->describeActivity($agentBlockType, $presetId),
		);

		return match (ActivityNodeType::tryFrom($nodeType ?? ''))
		{
			ActivityNodeType::TRIGGER => NodeType::Trigger,
			ActivityNodeType::COMPLEX => NodeType::Complex,
			ActivityNodeType::OPERATORS => NodeType::Operators,
			ActivityNodeType::SERVICE => NodeType::Services,
			ActivityNodeType::TOOL => NodeType::Tool,
			default => NodeType::Simple,
		};
	}

	/**
	 * The system name the block is saved under, which is what a connection addresses and what a `{=id:Output}`
	 * substitution written in another block resolves by (BRIEF § 4.3).
	 *
	 * The rename belongs to the one entry that is NOT held to the grammar, and there it reaches what the assistant
	 * AUTHORS. The internal assistant is asked for a numeric identifier by the contract of its own tool, and a
	 * numeric name is the one shape an array of activities keyed by name loses, so a name outside the grammar it
	 * composed itself is composed anew here
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool\SaveWorkflowTool::getInputSchema()}).
	 * The composed name is itself within the grammar, so a block Marta wrote can be sent back by the external
	 * agent that reads the same draft.
	 *
	 * A name the SOURCE graph already carries is kept even there ({@see self::carriesSourceName}): the assistant is
	 * handed the template as it stands ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService::getAgentTemplate()}),
	 * and a name it merely sent back is not one it authored. Those are the names this very converter wrote before
	 * the grammar existed - `MartaGeneratedId-{salt}-{n}`, composed with a separator - and renaming them costs the
	 * template the same round trip a rename costs on the external contour, with no check on this entry to notice.
	 *
	 * On the entry that IS held to it, every identifier reaching this point has already been judged by the very
	 * same grammar ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlockValidator})
	 * and is written as it arrived: it either fits the grammar, or it is one the published version - or the graph
	 * the agent was handed - already carries at this very point, which the check accepts as it stands (ALG-02).
	 * Renaming that second kind is what this rule exists against: the expressions addressing such a name live in
	 * the settings of other blocks and are rewritten nowhere, so the graph would be saved addressing a node that
	 * no longer carries the name, and silently (AC-35).
	 */
	private function makeActivityId(string $agentBlockId): string
	{
		$keptAsItArrived = $this->idsHeldToGrammar
			|| BlockIdGrammar::isAccepted($agentBlockId)
			|| $this->carriesSourceName($agentBlockId)
		;

		return $keptAsItArrived
			? $agentBlockId
			: "MartaGeneratedId_{$this->uniqueBlockSalt}_{$agentBlockId}"
		;
	}

	/**
	 * Whether the graph the caller was handed already carries this very name - the published version of the bound
	 * template, or the draft the caller read it from, whichever the assistant was given
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService::getAgentTemplate()}
	 * answers with the newer of the two, so both are read here and neither is assumed).
	 *
	 * The name alone, without the type the check pairs it with on the external contour (ALG-02): there the
	 * exemption lifts a REFUSAL, and pairing it with the type is what keeps an old name off a new block. Here
	 * nothing is refused - the entry is not held to the grammar at all - so a pair would buy no strictness and
	 * would cost the round trip of a node whose type the assistant legitimately changed, whose neighbours go on
	 * addressing it by name.
	 */
	private function carriesSourceName(string $agentBlockId): bool
	{
		$this->sourceNames ??= $this->readSourceNames();

		return array_key_exists($agentBlockId, $this->sourceNames);
	}

	/**
	 * The names of the source graph, flattened by {@see self::indexBaselineActivities} exactly as the baseline is,
	 * so a nested node counts the same as a top-level one: an expression resolves a name at any depth.
	 *
	 * Read at most once per conversion and only once a name outside the grammar has to be judged, which on the
	 * entry held to the grammar never happens at all.
	 *
	 * @return array<string, array{Type: string, Properties: array}>
	 */
	private function readSourceNames(): array
	{
		$names = $this->getBaselineActivities();
		$this->indexBaselineActivities($this->loadDraftTemplate($this->sourceDraftId) ?? [], $names);

		return $names;
	}

	/** The TEMPLATE of a draft as stored, read the same failure-tolerant way the published one is. */
	private function loadDraftTemplate(int $draftId): ?array
	{
		if ($draftId <= 0)
		{
			return null;
		}

		try
		{
			$templateData = WorkflowTemplateDraftTable::query()
				->where('ID', $draftId)
				->setSelect(['TEMPLATE_DATA'])
				->setLimit(1)
				->fetchObject()
				?->getTemplateData()
			;
		}
		catch (\Throwable)
		{
			return null;
		}

		$template = is_array($templateData) ? ($templateData['TEMPLATE'] ?? null) : null;

		return is_array($template) ? $template : null;
	}

	public function convertDraftToAgentTemplate(Draft $draft): AgentTemplate
	{
		return new AgentTemplate(
			blocks: $this->convertToAgentBlocks($draft->blocks),
			connections: $this->convertToAgentConnections($draft->connections),
		);
	}

	private function convertToAgentBlocks(BlockCollection $blocks): AgentBlockCollection
	{
		$agentBlocks = new AgentBlockCollection();
		foreach ($blocks as $block)
		{
			$agentBlocks->add($this->convertToAgentBlock($block));
		}

		return $agentBlocks;
	}

	private function convertToAgentBlock(Block $block): AgentBlock
	{
		$properties = $block->activityData->properties;

		return new AgentBlock(
			type: $block->activityData->type,
			// Пользовательское имя — из Properties.Title (не из системного node.title). Механика та же, что
			// в прод-обратном пути (AiAssistantWorkflowTemplateConverterService), но нормализация против
			// системного имени тут не нужна: прямой путь пишет Properties.Title только как настоящий
			// override. Ручной шаблон кладёт туда и системное имя, поэтому прод-reverse его нормализует.
			title: (string)($properties[self::TITLE_PROPERTY_NAME] ?? ''),
			id: $block->id,
			settings: $this->convertToAgentSettings($block),
			description: $this->extractDescription($properties),
			// The saved PresetId is the first source: the forward path stamps it on the activity exactly as the
			// manual editor does. Recovering it from Properties.Document stays the fallback for nodes saved
			// before the field was written - a preset applying no Document is not recoverable that way at all.
			presetId: $this->normalizePresetId($block->activityData->presetId)
				?? $this->metadataResolver->resolvePresetId(
					$block->activityData->type,
					$this->extractDocument($properties),
				),
		);
	}

	private function convertToAgentSettings(Block $block): AgentSettingCollection
	{
		$settings = new AgentSettingCollection();

		foreach ($block->activityData->properties as $name => $value)
		{
			// Title и EditorComment уходят агенту отдельными полями (title/description),
			// поэтому не дублируются в общей куче настроек блока.
			if ($name === self::TITLE_PROPERTY_NAME || $name === self::COMMENT_PROPERTY_NAME)
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: $name,
				value: $value,
			));
		}

		return $settings;
	}

	private function convertToAgentConnections(ConnectionCollection $connections): AgentConnectionCollection
	{
		$agentConnections = new AgentConnectionCollection();
		foreach ($connections as $connection)
		{
			$agentConnections->add($this->convertToAgentConnection($connection));
		}

		return $agentConnections;
	}

	private function convertToAgentConnection(Connection $connection): AgentConnection
	{
		return new AgentConnection(
			destinationBlockId: $connection->targetBlockId,
			sourceBlockId: $connection->sourceBlockId,
			sourcePortId: $connection->sourcePortId !== '' ? $connection->sourcePortId : null,
			targetPortId: $connection->targetPortId !== '' ? $connection->targetPortId : null,
		);
	}
}

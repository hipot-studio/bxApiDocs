<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Activity\Enum\ActivityPortRole;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupBlocksParseResult;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupConstantDeclaration;
use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\BizprocDesigner\Internal\Entity\AgentPortDefault;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph\AncestorIndex;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph\DirectedReachability;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockMetadataResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockOutputResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\CreateStorageNodePolicy;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SetupTemplateNodePolicy;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageColumnSchemaResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeClassifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeFingerprint;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AgentBlocksValidator
{
	/**
	 * Server-side DoS guard, not a business limit: layout is polynomial in the block count (see
	 * TopologyLayoutEngine). Single source of truth for the bound the Marta tool schema advertises via maxItems
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool\SaveWorkflowTool::getInputSchema()}),
	 * enforced here so the REST path is guarded too, not only the schema-validated Marta one.
	 */
	public const MAX_BLOCKS = 256;

	/**
	 * Server-side DoS guard on the setup wizard form of ONE node: a form that passes it has every item parsed, and
	 * the forward path writes a constant item back declaration by declaration. Items of every kind are counted
	 * rather than the constants alone - a delimiter, a heading and a description cost the same parse as a constant
	 * does - and the counting itself is done on the RAW form, so a form over the bound is turned down without ever
	 * paying the parse the bound exists to keep out. The richest shipped form declares seven constants, so this is
	 * a generous upper bound that never clips a legitimate wizard, not a business limit.
	 */
	public const MAX_SETUP_FORM_ITEMS = 64;

	/**
	 * Server-side DoS guard on the constant declarations of the whole template: the codes the wizard nodes of the
	 * graph declare together with the ones the published version already owns AND the ones the bound draft has
	 * accumulated. The draft belongs in the count because that is where a reverse write merges declarations and it
	 * never removes one, so counting the published version alone would leave the size of the draft bounded by the
	 * storage column and nothing else. Bites growth alone - at the ceiling an edit that only updates declarations
	 * the published wizard carries still passes - so a template that outgrew the bound before it existed keeps
	 * being editable and needs no migration.
	 */
	public const MAX_SETUP_DECLARATIONS = 128;

	/**
	 * The one loop condition kind an agent composes and therefore the only one whose shape this pass reads
	 * ({@see \CBPWhileActivity::AI_AUTHORABLE_CONDITION}). Spelled out rather than referenced through the class:
	 * the name is a catalog setting name here, and the graph pass reads settings without loading activity files.
	 */
	private const LOOP_AUTHORABLE_CONDITION = 'fieldcondition';

	private readonly ?AgentBlockValidator $injectedBlockValidator;
	private readonly ?ValidationContext $context;
	private readonly RequestSource $source;

	/**
	 * How strictly this graph is judged (TPL-02). Read in place of the request source, which this pass keeps only
	 * for the catalogs it builds: the storage order rule of ALG-03 is a check the external contour ADDS, and the
	 * internal assistant keeps the contract it has today.
	 */
	private readonly ValidationProfile $profile;

	private readonly CreateStorageNodePolicy $createStorageNodePolicy;
	private readonly SetupTemplateNodePolicy $setupTemplateNodePolicy;

	/**
	 * Built per run on the context enriched with the storage codes this template owns (ALG-11). An externally
	 * injected validator is taken as it is: a test double must never gain the enrichment, or a barrier resting
	 * on the owned codes would report green without ever being asked.
	 *
	 * Kept past the run because the enriched context lives nowhere else: reading it back off this property is the
	 * only way to tell the owned codes the main pass judged on from the ones it was handed. Nullable because
	 * validate() returns before building it on every malformed input, and a typed property left unset would answer
	 * that read with an Error instead of "there was no run".
	 */
	private ?AgentBlockValidator $blockValidator = null;

	/**
	 * Catalog lookups shared by the probe pass and the main pass of one validate() call. Both walk the same
	 * activity catalog with the same source, and a catalog walk is not memoised inside
	 * {@see \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher::searchByType()}, so a private cache per pass
	 * means every walk is paid twice. Sharing is safe: BlockDescriptionCache keys on block type, source, preset
	 * and document type, and AgentBlockMetadataResolver depends on the source alone, while the passes differ only
	 * by black list and context. Both are dropped at the start of every validate() all the same: the metadata
	 * resolver has no document type in its key, and a run keeps nothing of the previous one.
	 */
	private ?BlockDescriptionCache $blockDescriptionCache = null;
	private ?AgentBlockMetadataResolver $metadataResolver = null;

	/**
	 * Shared by every block validator this one builds, for the same reason the two above are: resolving the
	 * restricted names of a type loads condition files. Unlike them it is not dropped between runs - its key
	 * carries the document type, so an answer of one run cannot be read for another.
	 */
	private ?RestrictedSettingRegistry $restrictedSettings = null;

	/**
	 * The graph an expression reference is judged against (BRIEF § 4.2), assembled once per run before the first
	 * block is judged: the ancestors of a node and the outputs of a block are questions about the whole graph, and
	 * building them while the nodes are judged would make the verdict on one node depend on where another stands
	 * in the array. Null under the profile that adds no such check, so nothing is walked for it.
	 */
	private ?AncestorIndex $ancestorIndex = null;
	private ?BlockOutputResolver $outputResolver = null;

	private AgentBlockCollection $validBlocks;

	/**
	 * @var list<string>
	 */
	private array $blockIds = [];

	/**
	 * @var list<string> ids of the valid frame overlays (REST only). Frames carry no ports and are excluded
	 *      from the ordinary connections/connectivity check {@see AgentConnectionsValidator}.
	 */
	private array $frameBlockIds = [];

	/**
	 * @var array<string, array{input: list<string>, output: list<string>, dynamic?: bool}>
	 *      Known ports per block id. Complex nodes carry `dynamic: true` - their port set is projected
	 *      exhaustively from their own rules, so an unknown port is an error even when a direction is
	 *      empty (no "unknown ports -> skip check" bypass in the connections validator).
	 */
	private array $blockPortsMap = [];

	/**
	 * @var array<string, list<string>> loop-back input port ids per block id (loop nodes only)
	 */
	private array $blockLoopbackInputPortsMap = [];

	/**
	 * The loop nodes whose body is expressed on the OUTPUT side alone, which is the shape the input-based
	 * closure check cannot see ({@see AgentConnectionsValidator::validateLoopClosure()}): While carries the
	 * LoopBody role on its output o0 and closes the body back into its ordinary entry input, while ForEach owns
	 * a loop-back INPUT and is judged - and refused - there. Collected as the main pass admits a node, so the
	 * risk below rests on the very detail the verdict on the node rested on.
	 *
	 * @var list<array{id: string, path: string, bodyPorts: list<string>, block: AgentBlock}>
	 */
	private array $outputSideLoopNodes = [];

	/**
	 * Setup wizard forms of the graph, parsed once per node by the pass that precedes the verdicts and kept by
	 * block id. Reused when the node is judged instead of being parsed again: a second parse pays the entity
	 * selector registry of every constant a second time, which is the very cost {@see self::MAX_SETUP_FORM_ITEMS}
	 * exists to bound.
	 *
	 * @var array<string, SetupBlocksParseResult>
	 */
	private array $setupNodeForms = [];

	/**
	 * @var array<string, int> occurrences of every constant code across the wizard nodes of the graph (ALG-01)
	 */
	private array $setupGraphCodeCounts = [];

	/**
	 * Ids of the wizard nodes whose raw form is over {@see self::MAX_SETUP_FORM_ITEMS}. Such a node is left
	 * unparsed, so it declares nothing to the code counter either: it is refused by the bound whatever its items
	 * say, and one refused node refuses the whole entry.
	 *
	 * @var array<string, true>
	 */
	private array $setupOversizedNodeIds = [];

	/**
	 * Constants declared by the wizard nodes this pass ADMITTED outside the baseline, in the order it met them
	 * (TPL-03). Taken from the very parse the verdict rested on, so the graph is judged and written by one reading
	 * of the same form. A node the baseline carries verbatim contributes nothing: its form was never parsed, and
	 * applying what it declares would repaint constants the published version already agreed on.
	 *
	 * @var list<SetupConstantDeclaration>
	 */
	private array $setupConstantDeclarations = [];

	/**
	 * @var list<string>|null constant types the wizard allows for the document type of the run; null means the
	 *      process engine of this portal publishes no such reading, which the policy refuses by its own reason
	 */
	private ?array $setupAllowedConstantTypes = null;

	/** Whether the declarations of the template as a whole are over {@see self::MAX_SETUP_DECLARATIONS}. */
	private bool $setupDeclarationLimitExceeded = false;

	/**
	 * Create nodes of this graph the admission policy lets through, with the contract their columns state
	 * (ALG-03). A refused create node is left out: it owns no storage code, so it can neither prove an order nor
	 * disagree with another creator about one.
	 *
	 * @var list<array{id: string, code: string, path: string, contract: string, publishedVerbatim: bool}>
	 */
	private array $storageCreators = [];

	/**
	 * Nodes using storage this very graph has to create, with the address their verdict is reported at. A node
	 * whose reference the published version already answers for is not here at all
	 * ({@see self::collectStorageOrderNode()}).
	 *
	 * @var list<array{id: string, code: string, path: string}>
	 */
	private array $storageConsumers = [];

	/**
	 * The order verdict per storage code, computed on the first question about that code and read afterwards
	 * (ALG-03). The pair of traversals behind it does not depend on which node asks - it is the same creators
	 * against the same graph - so a template using one storage from a dozen nodes pays for one walk, and the
	 * verdict cannot differ between two nodes of the same code.
	 *
	 * Keyed by the NORMALISED code, the way ownership is indexed everywhere else
	 * ({@see StorageReferenceGuard::normalizeCode()}), or a case-shifted code would be resolved twice while
	 * addressing one storage.
	 *
	 * @var array<string, array{
	 *     conflict: bool,
	 *     authorised: bool,
	 *     hasCreator: bool,
	 *     reachedFromCreators: array<string, true>,
	 *     reachedBypassingCreators: array<string, true>,
	 * }>
	 */
	private array $storageOrderVerdicts = [];

	/**
	 * Whether some node of the graph moves control by something other than a connection, which leaves every
	 * order built on the connections unproven ({@see DirectedReachability::routesOutsideConnections()}).
	 */
	private bool $routesOutsideConnections = false;

	/**
	 * Non-blocking risks this pass named on a graph it did not refuse (DTO-02). Kept apart from the errors
	 * because an error decides the verdict and a warning never does; the caller carries them to the report
	 * ({@see SaveWorkflowValidator::validate()}).
	 *
	 * @var list<Error>
	 */
	private array $warnings = [];

	/**
	 * Storage the published version already names, indexed once per validator like the restricted names are: every
	 * field it reads is settled at construction, and re-indexing a code per node would repeat a regexp for each of
	 * the 256 blocks an entry may carry.
	 */
	private ?StorageAuthorizationContext $existingStorage = null;

	/**
	 * Columns the admitted create nodes of THIS graph declare, keyed by normalised storage code - the first
	 * source of the keys a value map of a consumer is judged against (BRIEF § 3.4). Collected by the same probe
	 * pass that collects the owned codes, so a consumer is answered by the same set wherever its creator stands
	 * in the array.
	 *
	 * @var array<string, mixed> null under a code its creators disagree about
	 */
	private array $graphStorageColumns = [];

	/**
	 * The column contract each collected declaration states, so a second creator of the same code is compared
	 * with the first one without reading the value twice.
	 *
	 * @var array<string, string>
	 */
	private array $graphStorageColumnContracts = [];

	/**
	 * Reads the columns of the storage a consumer node writes into, built once per run on the ENRICHED context
	 * ({@see StorageColumnSchemaResolver}). Null for an injected block validator: the caller wired the pass
	 * itself and owns what its blocks are described with.
	 */
	private ?StorageColumnSchemaResolver $storageColumns = null;

	public function __construct(
		?AgentBlockValidator $blockValidator = null,
		?ValidationContext $context = null,
		?CreateStorageNodePolicy $createStorageNodePolicy = null,
	)
	{
		$this->context = $context;
		// Fail-closed like ValidationContext itself: no context at all means the least permissive source.
		$this->source = $context?->source ?? RequestSource::Marta;
		$this->profile = ValidationProfile::of($context);
		$this->validBlocks = new AgentBlockCollection();
		$this->injectedBlockValidator = $blockValidator;
		$this->createStorageNodePolicy = $createStorageNodePolicy ?? new CreateStorageNodePolicy();
		$this->setupTemplateNodePolicy = new SetupTemplateNodePolicy();
	}

	/**
	 * Error texts are deliberately kept verbatim - the Marta model reads them as hints - so a message names
	 * `blocks` literally even though the address it is reported at comes from $path.
	 *
	 * @param mixed $connections the connection list of the same entry, as submitted. The order of creating and
	 *        using storage is a question about the graph and not about a block, so the pass that judges the blocks
	 *        needs the edges too (ALG-03); it reads them and never validates them - that stays with
	 *        {@see AgentConnectionsValidator}. Omitting them leaves the order unprovable, exactly as an empty
	 *        graph would.
	 */
	public function validate(
		mixed $blocks,
		DocumentDescription $documentType,
		string $path = '',
		mixed $connections = null,
	): Result
	{
		$this->blockIds = [];
		$this->frameBlockIds = [];
		$this->blockPortsMap = [];
		$this->blockLoopbackInputPortsMap = [];
		$this->outputSideLoopNodes = [];
		$this->blockDescriptionCache = null;
		$this->metadataResolver = null;
		$this->blockValidator = null;
		$this->ancestorIndex = null;
		$this->outputResolver = null;
		$this->setupNodeForms = [];
		$this->setupGraphCodeCounts = [];
		$this->setupOversizedNodeIds = [];
		$this->setupAllowedConstantTypes = null;
		$this->setupDeclarationLimitExceeded = false;
		$this->setupConstantDeclarations = [];
		$this->storageCreators = [];
		$this->storageConsumers = [];
		$this->storageOrderVerdicts = [];
		$this->graphStorageColumns = [];
		$this->graphStorageColumnContracts = [];
		$this->storageColumns = null;
		$this->routesOutsideConnections = false;
		$this->warnings = [];
		$this->validBlocks = new AgentBlockCollection();

		if (!is_array($blocks))
		{
			return (new Result())->addError(
				GraphError::at($path, 'blocks should be array', GraphErrorCode::GraphElementInvalid),
			);
		}

		if (empty($blocks))
		{
			return (new Result())->addError(
				GraphError::at($path, 'blocks should be not empty array', GraphErrorCode::GraphElementInvalid),
			);
		}

		if (count($blocks) > self::MAX_BLOCKS)
		{
			return (new Result())->addError(
				GraphError::at(
					$path,
					'blocks should contain at most ' . self::MAX_BLOCKS . ' items',
					GraphErrorCode::BlockLimitExceeded,
				),
			);
		}

		// Indexed before anything is judged, so the probe pass below and the main pass ask one and the same graph
		// and one and the same memo of resolved outputs.
		if ($this->profile->enforcesExpressionReferences())
		{
			$this->ancestorIndex = AncestorIndex::fromGraph($blocks, $connections);
			$this->outputResolver = new BlockOutputResolver($blocks);
		}

		// The owned codes are collected before the main pass, so a consumer is judged by the same set wherever
		// its creator stands in the array. The right-hand side is skipped for an injected validator: with no
		// enrichment to build, the graph is not walked for the codes either.
		$blockValidator = $this->injectedBlockValidator;
		if ($blockValidator === null)
		{
			$enrichedContext = $this->contextWithOwnedStorageCodes($blocks, $documentType, $path);
			// Built after the probe pass and never before it: the columns a consumer is judged against include
			// the ones the create nodes of this very graph declare, and the probe is what reads them.
			$this->storageColumns = new StorageColumnSchemaResolver(
				$this->blockDescriptionCache($enrichedContext),
				$enrichedContext,
				$this->graphStorageColumns,
			);
			$blockValidator = $this->createBlockValidator($enrichedContext);
		}
		$this->blockValidator = $blockValidator;

		// Unlike the pass above this one enriches no validator - it assembles what a wizard verdict rests on - so
		// it runs whoever built the block validator: the verdict may not depend on how the caller was wired.
		$this->scanSetupTemplateNodes($blocks, $documentType, $path);
		$this->scanRoutingModel($blocks);

		$result = new Result();
		foreach ($blocks as $key => $block)
		{
			$blockValidateResult = $blockValidator->validate(
				block: $block,
				documentType: $documentType,
				blackListIds: $this->blockIds,
				path: "{$path}.{$key}",
			);
			$result->addErrors($blockValidateResult->getErrors());
			// Read off the main pass alone: the probe pass judges the same node with the errors dropped, and a
			// risk collected there would be named twice for one node.
			$this->warnings = [...$this->warnings, ...$blockValidator->getWarnings()];
			$validBlock = $blockValidateResult->isSuccess() ? $blockValidator->getValidBlock() : null;
			if ($validBlock !== null)
			{
				// An immutable storage node is a passthrough of the published baseline only (ALG-01), checked
				// here rather than in the consumer guard: a forged create carrying a token code is out of the
				// baseline too, so the guard's token branch would never catch it. Outside the baseline the
				// create node still has the admission policy left (ALG-10); the rest stay refused outright.
				if (StorageNodeClassifier::isImmutable($validBlock->type))
				{
					$fingerprint = StorageNodeFingerprint::of($validBlock->type, $validBlock->settings);
					$baseline = $this->context?->baselineImmutableFingerprints ?? [];
					if (!isset($baseline[$fingerprint]))
					{
						$result->addErrors(
							$this->admitNodeOutsideBaseline($validBlock, "{$path}.{$key}")->getErrors(),
						);
					}
				}

				$result->addErrors(
					$this->validatePublishedReferenceScope($validBlock, $documentType, "{$path}.{$key}")->getErrors(),
				);

				$this->validBlocks->add($validBlock);
				$this->collectStorageOrderNode($validBlock, "{$path}.{$key}");

				if (FrameBlockMatcher::matches($validBlock->type, $validBlock->presetId))
				{
					$this->frameBlockIds[] = $validBlock->id;
				}

				$blockDetail = $blockValidator->getValidBlockDetail();
				if ($blockDetail !== null)
				{
					if ($validBlock->rules !== null)
					{
						// Complex node: ports are projected from the block's own rules (dynamic), not from
						// the static catalog topology. Marked `dynamic` so the connections validator does not
						// fall back to its "unknown ports -> skip check" bypass and let the agent reference a
						// non-existent dynamic port. Aux (tool) ports are source endpoints too, so they join
						// the output set. Complex nodes have no loop-back inputs.
						$this->blockPortsMap[$validBlock->id] = [
							'input' => $validBlock->rules->getInputPortIds(),
							'output' => [
								...$validBlock->rules->getOutputPortIds(),
								...$validBlock->rules->getAuxPortIds(),
							],
							'dynamic' => true,
						];
					}
					else
					{
						$this->blockPortsMap[$validBlock->id] = $blockDetail->getPortIds();

						$loopbackInputPorts = $blockDetail->getLoopbackInputPortIds();
						if (!empty($loopbackInputPorts))
						{
							$this->blockLoopbackInputPortsMap[$validBlock->id] = $loopbackInputPorts;
						}
						else
						{
							$this->collectOutputSideLoopNode($validBlock, $blockDetail, "{$path}.{$key}");
						}
					}
				}
			}
			if ($blockValidator->getId() !== null)
			{
				$this->blockIds[] = $blockValidator->getId();
			}
		}

		$result->addErrors($this->validateStorageOrder($connections)->getErrors());
		$this->collectStartTriggerRisk();
		$this->collectLoopRisks($connections);

		return $result;
	}

	/**
	 * A few values intentionally bypass the typed settings passport. Their value-level checks remain in the block
	 * validator; this graph-level check additionally holds each one to the published node and location that owns it.
	 */
	private function validatePublishedReferenceScope(
		AgentBlock $block,
		DocumentDescription $documentType,
		string $path,
	): Result
	{
		$context = $this->context;
		if ($context === null)
		{
			return new Result();
		}

		$result = new Result();
		if (AgentStartTriggerMatcher::matches($block->type))
		{
			$fingerprint = AgentStartTriggerMatcher::referenceFingerprint($block);
			if (!isset($context->baselineVerbatimStartTriggerReferences[$fingerprint]))
			{
				$result->addError(GraphError::at(
					$path,
					"{$path} agent start trigger must match a published template node exactly",
					GraphErrorCode::ImmutableNodePolicyViolation,
				));
			}
		}

		if (StorageNodeClassifier::isConsumer($block->type))
		{
			$fingerprint = StorageNodeFingerprint::of($block->type, $block->settings);
			if (
				isset($context->baselineStaticNodeFingerprints[$fingerprint])
				&& $context->baselineStaticNodeReferences !== []
				&& !isset($context->baselineStaticNodeReferences[StorageNodeFingerprint::referenceOf(
					$block->id,
					$block->type,
					$block->settings,
				)])
			)
			{
				$result->addError(GraphError::at(
					"{$path}.id",
					"{$path}.id static storage consumer must keep its published node identity",
					GraphErrorCode::ImmutableNodePolicyViolation,
				));
			}
		}

		return $result->addErrors(
			$this->validateRestrictedSettingReferences($block, $documentType, $path)->getErrors(),
		);
	}

	private function validateRestrictedSettingReferences(
		AgentBlock $block,
		DocumentDescription $documentType,
		string $path,
	): Result
	{
		$context = $this->context;
		if ($context === null || !$context->hasRestrictedSettingReferences())
		{
			return new Result();
		}

		$this->restrictedSettings ??= new RestrictedSettingRegistry();
		$result = new Result();
		$restrictedNames = $this->restrictedSettings->namesFor($block->type, $documentType);
		foreach ($block->settings as $setting)
		{
			if (!in_array(mb_strtolower($setting->name), $restrictedNames, true))
			{
				continue;
			}

			$this->assertRestrictedSettingReference(
				$result,
				"block:{$block->id}",
				$block->type,
				$setting->name,
				$setting->value,
				"{$path}.settings.{$setting->name}",
			);
		}

		if ($block->rules === null)
		{
			return $result;
		}

		foreach ($block->rules->portRules as $portId => $portRuleList)
		{
			foreach ($portRuleList as $rule)
			{
				foreach ($rule->constructions as $constructionIndex => $construction)
				{
					if (
						$construction->type !== AgentComplexConstruction::TYPE_ACTION
						&& $construction->type !== AgentComplexConstruction::TYPE_FILTER
					)
					{
						continue;
					}

					$activityCode = $construction->expression['activityCode'] ?? null;
					$settings = $construction->expression['settings'] ?? null;
					if (!is_string($activityCode) || !is_array($settings))
					{
						continue;
					}

					$location = "block:{$block->id}|port:{$portId}|rule:{$rule->id}|construction:{$constructionIndex}";
					$restrictedNames = $this->restrictedSettings->namesFor($activityCode, $documentType);
					foreach ($settings as $name => $value)
					{
						if (is_string($name) && in_array(mb_strtolower($name), $restrictedNames, true))
						{
							$this->assertRestrictedSettingReference(
								$result,
								$location,
								$activityCode,
								$name,
								$value,
								"{$path}.rules.{$portId}.{$rule->id}.constructions.{$constructionIndex}.settings.{$name}",
							);
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * The location half of ALG-04, and it parts on the payload exactly as the value half does
	 * ({@see AgentBlockValidator::authorizeRestrictedSettings()}): an empty setting is duplicated by copying
	 * nothing, so there is no reuse for this check to close.
	 *
	 * The locations it admits are those of the graphs the server wrote, the published version and the graph the
	 * agent was handed alike ({@see ValidationContext::carriesRestrictedSettingReference()}), because the value half
	 * reads the two as one as well: admitting the value of a draft node while refusing the node it stands on would
	 * leave the round-trip broken by the other half of the same rule.
	 */
	private function assertRestrictedSettingReference(
		Result $result,
		string $location,
		string $type,
		string $name,
		mixed $value,
		string $path,
	): void
	{
		if (RestrictedSettingRegistry::carriesNoPayload($value))
		{
			return;
		}

		if (!$this->context->carriesRestrictedSettingReference($location, $type, $name, $value))
		{
			$result->addError(GraphError::at(
				$path,
				"{$path} must match the published template location for this executable setting",
				GraphErrorCode::ImmutableNodePolicyViolation,
			));
		}
	}

	/**
	 * Judges an immutable storage node the published baseline does not carry. Both immutable types have a resource
	 * policy of their own past this point: the wizard node goes to {@see SetupTemplateNodePolicy} and the create
	 * node to {@see CreateStorageNodePolicy} (ALG-10). Each refusal carries the text of the policy that made it -
	 * the agent's only channel of self-correction - and leaves a diagnostic record of its own. What is left of the
	 * unconditional refusal answers for a type neither policy judges, and for a create node reaching here with no
	 * context: with no template there is no code to bind, so the policy is not consulted at all - the same
	 * fail-closed reading of a missing context the rest of the pipeline holds. The wizard branch is taken whatever
	 * the context, because its policy names that state by a reason of its own.
	 *
	 * The wizard is recognised by {@see SetupTemplateNodeMatcher} and not by the storage taxonomy of
	 * {@see StorageNodeClassifier}: it touches no storage, and a predicate for it there would invent a commonality
	 * the two node families do not have.
	 *
	 * The two states behind the unconditional refusal share the text of the policy but not the class of the
	 * failure: a type no policy judges is the graph breaking the rule, a node reaching here with no context is
	 * the server missing what the rule is judged against.
	 */
	private function admitNodeOutsideBaseline(AgentBlock $block, string $path): Result
	{
		if (SetupTemplateNodeMatcher::matches($block->type))
		{
			return $this->admitSetupTemplateNode($block, $path);
		}

		$context = $this->context;
		if ($context === null)
		{
			return (new Result())->addError(GraphError::at(
				$path,
				"{$path} " . CreateStorageNodePolicy::REASON_TYPE_NOT_ALLOWED,
				GraphErrorCode::ContextResolutionFailed,
			));
		}

		if (!StorageNodeClassifier::isStorageCreator($block->type))
		{
			return (new Result())->addError(GraphError::at(
				$path,
				"{$path} " . CreateStorageNodePolicy::REASON_TYPE_NOT_ALLOWED,
				GraphErrorCode::ImmutableNodePolicyViolation,
			));
		}

		$verdict = $this->createStorageNodePolicy->admit($block->type, $block->settings, $context);
		if ($verdict->allowed)
		{
			return new Result();
		}

		$this->createStorageNodePolicy->reportRejection($block->id, $block->settings, $verdict, $context);

		return (new Result())->addError(GraphError::at(
			$path,
			"{$path} {$verdict->reason}",
			GraphErrorCode::ImmutableNodePolicyViolation,
		));
	}

	/**
	 * Judges a setup wizard node the published baseline does not carry verbatim. Everything the verdict rests on is
	 * assembled by {@see self::scanSetupTemplateNodes()} before the first verdict, so that one and the same node
	 * gets one and the same verdict wherever it stands in the array.
	 *
	 * The two technical ceilings are held here rather than inside the policy: the numbers bound what THIS entry
	 * accepts and belong next to {@see self::MAX_BLOCKS}, while the wording of a refusal belongs to the policy,
	 * which is the single account of why a wizard node is turned down. Both arrive already decided from
	 * {@see self::scanSetupTemplateNodes()} - the item one before the form of the node was parsed at all.
	 *
	 * A refusal with no context at all cannot be recorded - the record names the template and the entry, and there
	 * is neither - so the agent still gets the reason and the diagnostic channel stays silent.
	 *
	 * An admitted node hands its declarations on to the write (TPL-03). They are taken from the parse the verdict
	 * was made on and only ever from a node that reached this point, so what a refused - or a verbatim - node
	 * declares is never applied.
	 */
	private function admitSetupTemplateNode(AgentBlock $block, string $path): Result
	{
		$parsedForm = $this->setupNodeForms[$block->id] ?? null;

		$verdict = $this->setupTemplateNodePolicy->admit(
			$block->type,
			$parsedForm,
			$this->setupAllowedConstantTypes,
			$this->setupGraphCodeCounts,
			isset($this->setupOversizedNodeIds[$block->id]),
			$this->setupDeclarationLimitExceeded,
			$this->context,
		);
		if ($verdict->allowed)
		{
			if ($parsedForm !== null)
			{
				$this->setupConstantDeclarations = [
					...$this->setupConstantDeclarations,
					...$parsedForm->declarations,
				];
			}

			return new Result();
		}

		if ($this->context !== null)
		{
			$this->setupTemplateNodePolicy->reportRejection($block->id, $verdict, $this->context);
		}

		return (new Result())->addError(GraphError::at(
			$path,
			"{$path} {$verdict->reason}",
			GraphErrorCode::ImmutableNodePolicyViolation,
		));
	}

	/**
	 * Reads the setup wizard nodes of the graph the published baseline does not carry verbatim, and assembles what
	 * their verdicts rest on: the form of each node, how many times every constant code occurs across them all, and
	 * whether the declarations of the template as a whole are over the ceiling. Assembled here rather than inside
	 * the policy for the same reason the owned storage codes are: a value spanning several nodes that grew while
	 * the nodes were judged would make the verdict depend on the order of the blocks.
	 *
	 * The pass walks the nodes instead of asking the policy - the storage pass above can ask, because there
	 * admission does not depend on what the pass collects, while here it does. So the codes of every wizard node it
	 * parses are counted whatever the verdict on that node turns out to be, or two nodes declaring one code would
	 * legitimise each other. A node over the item bound is the one exception: it is refused by that bound alone,
	 * which refuses the entry as a whole, so nothing is left for its codes to decide.
	 *
	 * A node the baseline carries verbatim contributes its codes to the graph-wide uniqueness check, but its parsed
	 * declarations stay out of setupNodeForms. It remains admitted before the policy is consulted and never reaches
	 * the write path.
	 */
	private function scanSetupTemplateNodes(array $blocks, DocumentDescription $documentType, string $path): void
	{
		$context = $this->context;
		if ($context === null)
		{
			// No template to compare a form against: the policy refuses every wizard node by that state alone.
			return;
		}

		$probe = null;
		foreach ($blocks as $block)
		{
			$type = is_array($block) ? ($block['type'] ?? null) : null;
			if (!SetupTemplateNodeMatcher::matches($type))
			{
				continue;
			}

			$probe ??= $this->createBlockValidator($context);
			if (!$probe->validate($block, $documentType, [], $path)->isSuccess())
			{
				continue;
			}

			$validBlock = $probe->getValidBlock();
			if ($validBlock === null)
			{
				continue;
			}

			$isBaselineImmutable = isset($context->baselineImmutableFingerprints[
				StorageNodeFingerprint::of($validBlock->type, $validBlock->settings)
			]);

			// Two wizard nodes under one id: the main pass refuses such a graph through its black list, and what
			// this pass keeps is the reading of the node it met FIRST - a later node overwriting it would have the
			// verdict on one node made on the form of another.
			if (
				isset($this->setupNodeForms[$validBlock->id])
				|| isset($this->setupOversizedNodeIds[$validBlock->id])
			)
			{
				continue;
			}

			// One reading of the settings of the node feeds both the bound and the parse it bounds.
			$rawForm = SetupTemplateNodePolicy::readRawForm($validBlock->settings);

			// The item bound before the parse it bounds: counting the raw form reads no item of it, so a node over
			// the bound costs the entry nothing beyond the count and is refused by that bound alone.
			if (SetupTemplateNodePolicy::countFormItems($rawForm) > self::MAX_SETUP_FORM_ITEMS)
			{
				$this->setupOversizedNodeIds[$validBlock->id] = true;

				continue;
			}

			$parsedForm = SetupTemplateNodePolicy::parseNodeForm($rawForm);
			if ($parsedForm === null)
			{
				// The engine publishes no parse entry: the policy answers for that state by a reason of its own.
				continue;
			}

			foreach ($parsedForm->declarations as $declaration)
			{
				$this->setupGraphCodeCounts[$declaration->code]
					= ($this->setupGraphCodeCounts[$declaration->code] ?? 0) + 1;
			}

			if (!$isBaselineImmutable)
			{
				$this->setupNodeForms[$validBlock->id] = $parsedForm;
			}
		}

		if ($this->setupNodeForms === [])
		{
			return;
		}

		// Once per pass, and only with a node to judge: reading the list includes the activity file of the wizard.
		$this->setupAllowedConstantTypes = SetupTemplateNodePolicy::readAllowedConstantTypes($documentType);

		$declaredCodes = [
			...($context->baselineWizardCodes ?? []),
			...$context->draftWizardCodes,
			...array_keys($this->setupGraphCodeCounts),
		];
		$this->setupDeclarationLimitExceeded = count(array_unique($declaredCodes)) > self::MAX_SETUP_DECLARATIONS;
	}

	/**
	 * Whether the graph moves control by anything the connections do not state, which is what the order rule can
	 * and cannot be proven on (ALG-03). Read off the types as SUBMITTED rather than off the admitted blocks: the
	 * completeness of the model is a property of the graph in front of the pass, and a node the catalog turns
	 * down still says what the graph was written as.
	 *
	 * A pass of its own, before the first verdict, for the reason the other two have one: a value spanning the
	 * whole graph that grew while the nodes were judged would make the verdict on one node depend on where
	 * another stands in the array.
	 */
	private function scanRoutingModel(array $blocks): void
	{
		if (!$this->profile->enforcesStorageOrder())
		{
			return;
		}

		foreach ($blocks as $block)
		{
			$type = is_array($block) ? ($block['type'] ?? null) : null;
			if (is_string($type) && DirectedReachability::routesOutsideConnections($type))
			{
				$this->routesOutsideConnections = true;

				return;
			}
		}
	}

	/**
	 * Remembers what the order rule of ALG-03 is judged on, taken from the node the main pass has just admitted:
	 * the create nodes the policy lets through and the nodes using storage this graph has to create.
	 *
	 * Collected while the blocks are judged rather than walked for a second time, so the reading the order rests
	 * on is the very reading the verdict on the node rested on.
	 *
	 * Two families of node are deliberately left out of the consumers, and each for the reason the storage barrier
	 * itself gives ({@see StorageReferenceGuard}):
	 *   - a node addressing storage by id names no code the flow could be ordered against - the runtime prefers
	 *     the id over the code - and the barrier has already answered for it;
	 *   - a node the published version carries byte for byte is an authorised reference to storage that predates
	 *     this graph (rule 4), so demanding a creator for it would cost a published template its round-trip.
	 * A code the barrier cannot read whole is left out on both sides: it is refused there, and matching it as text
	 * would order a graph against a storage nobody can name.
	 */
	private function collectStorageOrderNode(AgentBlock $block, string $path): void
	{
		$context = $this->context;
		if ($context === null || !$this->profile->enforcesStorageOrder())
		{
			return;
		}

		$code = StorageNodeClassifier::extractStorageCode($block->settings) ?? '';
		if ($code === '' || !StorageReferenceGuard::isFullyInterpretableCode($code))
		{
			return;
		}

		if (StorageNodeClassifier::isStorageCreator($block->type))
		{
			if (!$this->createStorageNodePolicy->admit($block->type, $block->settings, $context)->allowed)
			{
				return;
			}

			$this->storageCreators[] = [
				'id' => $block->id,
				'code' => $code,
				'path' => $path,
				'contract' => StorageNodeFingerprint::columnContractOf($block->settings),
				'publishedVerbatim' => isset($context->baselineImmutableFingerprints[
					StorageNodeFingerprint::of($block->type, $block->settings)
				]),
			];

			return;
		}

		if (!StorageNodeClassifier::isConsumer($block->type))
		{
			return;
		}

		if (StorageReferenceGuard::addressesStorageById(StorageNodeClassifier::extractStorageId($block->settings)))
		{
			return;
		}

		if (isset($context->baselineStaticNodeFingerprints[
			StorageNodeFingerprint::of($block->type, $block->settings)
		]))
		{
			return;
		}

		$this->storageConsumers[] = ['id' => $block->id, 'code' => $code, 'path' => $path];
	}

	/**
	 * The order of creating and using storage, proven on the graph the entry submitted (ALG-03). Blocking where
	 * the graph cannot work - no creator of the storage runs before the node using it, or several creators
	 * disagree on what the storage is - and a warning where the connections cannot settle the question.
	 *
	 * The verdict is asked per storage code and computed once for it, so a template using one storage from a
	 * dozen nodes is walked once and every one of them is answered the same.
	 */
	private function validateStorageOrder(mixed $connections): Result
	{
		$result = new Result();
		if ($this->storageCreators === [] && $this->storageConsumers === [])
		{
			return $result;
		}

		$reachability = DirectedReachability::fromConnections($connections);
		$entryPoints = $reachability->entryPoints($this->connectableBlockIds());

		foreach ($this->storageCreators as $creator)
		{
			if ($this->storageOrderVerdict($creator['code'], $reachability, $entryPoints)['conflict'])
			{
				$result->addError(GraphError::at(
					$creator['path'],
					"{$creator['path']} storage create nodes of one storage code must declare the same columns",
					GraphErrorCode::StorageCreatorConflict,
				));
			}
		}

		foreach ($this->storageConsumers as $consumer)
		{
			$verdict = $this->storageOrderVerdict($consumer['code'], $reachability, $entryPoints);
			// A conflicted code has no single storage to be ordered against, and its creators are already
			// refused: adding an order problem on top would send the agent after a second, dependent cause.
			if ($verdict['authorised'] || $verdict['conflict'])
			{
				continue;
			}

			// Routing the connections cannot express softens what the ROUTE proves and nothing else: with no
			// creator in the graph at all, no node creates the storage whichever way control moves.
			if ($this->routesOutsideConnections && $verdict['hasCreator'])
			{
				$this->warnings[] = GraphError::at(
					$consumer['path'],
					"{$consumer['path']} the order of creating and using this storage cannot be proven: the graph"
					. ' moves control outside its connections',
					GraphErrorCode::StorageOrderUnknown,
				);

				continue;
			}

			if (!isset($verdict['reachedFromCreators'][$consumer['id']]))
			{
				$result->addError(GraphError::at(
					$consumer['path'],
					"{$consumer['path']} the storage this node uses is created by no node that runs before it",
					GraphErrorCode::StorageOrderInvalid,
				));

				continue;
			}

			if (isset($verdict['reachedBypassingCreators'][$consumer['id']]))
			{
				$this->warnings[] = GraphError::at(
					$consumer['path'],
					"{$consumer['path']} this node can be reached by a route that passes no node creating the"
					. ' storage it uses',
					GraphErrorCode::StorageOrderRisk,
				);
			}
		}

		return $result;
	}

	/**
	 * A graph no event can start (D-7). Named as a risk and never as a refusal: an agent may legitimately build a
	 * template in more than one call, and the graph it saves halfway would otherwise be turned down over the
	 * entry it has not written yet.
	 *
	 * "No trigger at all" is the whole of the signal, and it is deliberately narrower than "no trigger the
	 * compatible launch path searches for". A run of a node template starts the activity it is HANDED
	 * ({@see \CBPNodeWorkflowActivity::getStartActivityNames()}): a trigger that fires passes its own name in
	 * `CBPDocument::PARAM_TRIGGER_EVENT`, and only a launch handing over no name at all falls back to searching
	 * the graph for a Manual/Create/Edit trigger class. So a graph whose only entry is, say, a CRM field-changed
	 * trigger starts perfectly well and must not be warned about, while a graph carrying no trigger has neither
	 * road: the search answers with nothing and the run ends at once on `BPNWA_EXECUTE_ERROR_NO_TRIGGER`.
	 *
	 * The predicate is the one the rest of this contour classifies a node by ({@see ActivityRegistry::isTrigger()}),
	 * asked of blocks the main pass has already admitted - so every type here is one the catalog of this portal
	 * resolved, and the fallback the predicate keeps for an unresolvable activity is never the answer that decides
	 * this risk.
	 */
	private function collectStartTriggerRisk(): void
	{
		$hasBlocks = false;
		$registry = new ActivityRegistry();
		foreach ($this->validBlocks as $block)
		{
			/** @var AgentBlock $block */
			$hasBlocks = true;
			if ($registry->isTrigger($block->type))
			{
				return;
			}
		}

		if (!$hasBlocks)
		{
			return;
		}

		$this->warnings[] = GraphError::unaddressed(
			'the graph carries no trigger, so no event can start this process: a run finds no start trigger and'
			. ' ends at once',
			GraphErrorCode::StartTriggerMissing,
		);
	}

	/**
	 * The two ways a loop of the graph does not loop (D-8), both named as risks. Neither is turned into a refusal:
	 * BRIEF § 7.5 leaves the closure of a While deliberately unjudged, and the joiner of a condition is a value
	 * the graph is free to carry - the published version may already hold either.
	 *
	 * @param mixed $connections connections as submitted, read exactly as the order rule of ALG-03 reads them
	 */
	private function collectLoopRisks(mixed $connections): void
	{
		if ($this->outputSideLoopNodes === [])
		{
			return;
		}

		$reachability = DirectedReachability::fromConnections($connections);
		foreach ($this->outputSideLoopNodes as $loop)
		{
			$this->collectLoopBodyReturnRisk($loop, $connections, $reachability);
			$this->collectLoopConditionRisk($loop);
		}
	}

	/**
	 * A loop whose body never leads back to it. What the runtime does with such a graph is not an endless loop but
	 * its opposite: the node model keeps every block a flat child of the root, so a While hands control to its
	 * body through the LoopBody output and gets it back only along an edge that returns
	 * ({@see \CBPWhileActivity::tryNextIteration()} sets the body output and closes;
	 * {@see \Bitrix\Bizproc\Public\Activity\Mixins\ChildFlowTraversal::onEvent()} routes on from whatever port a
	 * child closed on and ends the branch where no edge continues). The body therefore runs once and the branch
	 * stops there.
	 *
	 * A loop with no body at all is a different shape and no risk of this kind: it exits at once through its
	 * "out" port ({@see \CBPWhileActivity::execute()} tracks `BPWA_ERROR_EMPTY_BODY`), so nothing is repeated and
	 * nothing is lost.
	 *
	 * @param array{id: string, path: string, bodyPorts: list<string>, block: AgentBlock} $loop
	 */
	private function collectLoopBodyReturnRisk(
		array $loop,
		mixed $connections,
		DirectedReachability $reachability,
	): void
	{
		// A graph moving control outside its connections leaves every route unproven, exactly as it does for the
		// storage order: the body may well return by a road no edge of the graph states.
		if ($this->routesOutsideConnections)
		{
			return;
		}

		$bodyEntries = self::loopBodyEntryPoints($connections, $loop['id'], $loop['bodyPorts']);
		if ($bodyEntries === [])
		{
			return;
		}

		$reached = $reachability->reachableFrom($bodyEntries);
		if (isset($reached[$loop['id']]))
		{
			return;
		}

		$this->warnings[] = GraphError::at(
			$loop['path'],
			"{$loop['path']} the body of this loop leads nowhere back into the loop block, so on a run the body"
			. ' is executed once and the branch ends there instead of repeating',
			GraphErrorCode::LoopNotClosed,
		);
	}

	/**
	 * A loop condition that is true whatever the document holds, which leaves the loop nothing to stop it. The
	 * joiner of an item glues it to the PREVIOUS one, so the joiner of the FIRST item has no previous item to
	 * glue to and must be AND; anything else opens a fresh OR branch and leaves the `true` the group starts with
	 * untouched, which no later item can then take back
	 * ({@see \Bitrix\Bizproc\Activity\ConditionGroup::evaluate()}). The reading of the fourth element is the
	 * runtime's own ({@see \CBPActivityCondition::getJoiner()}): empty is AND, anything else is OR - so `0`, `'0'`,
	 * an empty string and an absent element are all the safe value, and the catalog says exactly this in the
	 * type description of the setting.
	 *
	 * Only the kind an agent composes is read. Every other condition kind is carried verbatim from the published
	 * version ({@see \CBPWhileActivity::getAiRestrictedSettingNames()}), so a risk named on one would be about a
	 * value the agent may not even change; and their shapes are parsed by their own activities, which this pass
	 * does not speak for.
	 *
	 * The item judged is the first the runtime EVALUATES rather than the first written: an item naming no field
	 * is dropped before the group is built ({@see \CBPFieldCondition::evaluate()}), so it is not the item whose
	 * joiner decides.
	 *
	 * @param array{id: string, path: string, bodyPorts: list<string>, block: AgentBlock} $loop
	 */
	private function collectLoopConditionRisk(array $loop): void
	{
		$condition = self::settingValue($loop['block']->settings, self::LOOP_AUTHORABLE_CONDITION);
		if (!is_array($condition))
		{
			return;
		}

		foreach ($condition as $item)
		{
			if (!is_array($item) || empty($item[0]))
			{
				continue;
			}

			if (!empty($item[3]))
			{
				$this->warnings[] = GraphError::at(
					$loop['path'],
					"{$loop['path']} the first condition of this loop carries a joiner other than 0, which makes"
					. ' the whole condition true whatever the document holds: nothing in it can stop the loop, and'
					. ' the process repeats until the iteration limit of the portal ends it',
					GraphErrorCode::LoopConditionAlwaysTrue,
				);
			}

			return;
		}
	}

	/**
	 * Where the body of one loop node begins: the blocks its LoopBody outputs lead to. A connection sent without
	 * a source port falls back to the zero index exactly as the converters do
	 * ({@see AgentPortDefault::SOURCE_PORT_ID}), and that default IS the body port of a While - so a body drawn
	 * as an ordinary chain is read as a body and not as an exit.
	 *
	 * @param list<string> $bodyPorts
	 * @return list<string>
	 */
	private static function loopBodyEntryPoints(mixed $connections, string $blockId, array $bodyPorts): array
	{
		if (!is_array($connections))
		{
			return [];
		}

		$entryPoints = [];
		foreach ($connections as $connection)
		{
			if (!is_array($connection) || ($connection['sourceBlockId'] ?? null) !== $blockId)
			{
				continue;
			}

			$portId = $connection['sourcePortId'] ?? null;
			$portId = is_string($portId) && $portId !== '' ? $portId : AgentPortDefault::SOURCE_PORT_ID;
			$destination = $connection['destinationBlockId'] ?? null;
			if (in_array($portId, $bodyPorts, true) && is_string($destination) && $destination !== '')
			{
				$entryPoints[$destination] = true;
			}
		}

		return array_keys($entryPoints);
	}

	/**
	 * Remembers a loop node whose body is expressed on the OUTPUT side alone
	 * ({@see self::$outputSideLoopNodes}). Read off the detail the main pass has just admitted, so the risk and
	 * the verdict on the node rest on one and the same topology.
	 */
	private function collectOutputSideLoopNode(AgentBlock $block, BlockTypeDetail $blockDetail, string $path): void
	{
		$bodyPorts = [];
		foreach ($blockDetail->nodeSettings?->ports?->output?->ports ?? [] as $port)
		{
			if ($port->id !== '' && $port->role === ActivityPortRole::LoopBody)
			{
				$bodyPorts[] = $port->id;
			}
		}

		if ($bodyPorts === [])
		{
			return;
		}

		$this->outputSideLoopNodes[] = [
			'id' => $block->id,
			'path' => $path,
			'bodyPorts' => $bodyPorts,
			'block' => $block,
		];
	}

	/**
	 * The value the block carries under this setting name, matched without regard to case exactly as every reader
	 * of a setting name in this contour matches it.
	 */
	private static function settingValue(AgentSettingCollection $settings, string $name): mixed
	{
		$wanted = mb_strtolower($name);
		foreach ($settings as $setting)
		{
			/** @var AgentSetting $setting */
			if (mb_strtolower($setting->name) === $wanted)
			{
				return $setting->value;
			}
		}

		return null;
	}

	/**
	 * The order verdict of one storage code, in the normative order of ALG-03 and memoised whatever it turns out
	 * to be - a refusal included, so the same code cannot be resolved twice and answered differently.
	 *
	 * Creators of one code are one contract when their columns state the same thing and a conflict when they do
	 * not: the storage a code names is single, so two nodes building it differently leave the graph without an
	 * answer to what the storage IS. Neither the first creator nor the union of what they declare may settle it -
	 * the first depends on where a block stands in the array, the second invents a storage no node asked for. A
	 * conflict the published version already carries unchanged is left alone, so a template that grew one keeps
	 * round-tripping; editing any of the nodes that carry it makes it this entry's conflict to resolve.
	 *
	 * @param list<string> $entryPoints
	 * @return array{
	 *     conflict: bool,
	 *     authorised: bool,
	 *     hasCreator: bool,
	 *     reachedFromCreators: array<string, true>,
	 *     reachedBypassingCreators: array<string, true>,
	 * }
	 */
	private function storageOrderVerdict(
		string $code,
		DirectedReachability $reachability,
		array $entryPoints,
	): array
	{
		$key = StorageReferenceGuard::normalizeCode($code);
		if (isset($this->storageOrderVerdicts[$key]))
		{
			return $this->storageOrderVerdicts[$key];
		}

		$contracts = [];
		$creatorIds = [];
		$publishedVerbatim = true;
		foreach ($this->storageCreators as $creator)
		{
			if (StorageReferenceGuard::normalizeCode($creator['code']) !== $key)
			{
				continue;
			}

			$contracts[$creator['contract']] = true;
			$creatorIds[$creator['id']] = true;
			$publishedVerbatim = $publishedVerbatim && $creator['publishedVerbatim'];
		}

		$verdict = [
			'conflict' => count($contracts) > 1 && !$publishedVerbatim,
			'authorised' => false,
			'hasCreator' => $creatorIds !== [],
			'reachedFromCreators' => [],
			'reachedBypassingCreators' => [],
		];

		if (!$verdict['conflict'])
		{
			// Storage the published version already names exists before this graph runs, so nothing here has to
			// create it and its order is not this entry's question (BRIEF § 4.1).
			$verdict['authorised'] = $this->existingStorage()->declaresStorageCode($code);
		}

		if (!$verdict['conflict'] && !$verdict['authorised'] && $verdict['hasCreator'])
		{
			$verdict['reachedFromCreators'] = $reachability->reachableFrom(array_keys($creatorIds));
			$verdict['reachedBypassingCreators'] = $reachability->reachableFrom($entryPoints, $creatorIds);
		}

		return $this->storageOrderVerdicts[$key] = $verdict;
	}

	/**
	 * Storage that exists apart from this graph, read from the published baseline ALONE. The owned codes are
	 * deliberately not given to it: those hold the create nodes of the graph under validation, and a submitted
	 * node must not authorise the very code whose order is in question.
	 */
	private function existingStorage(): StorageAuthorizationContext
	{
		return $this->existingStorage ??= new StorageAuthorizationContext(
			templateId: $this->context?->templateId ?? 0,
			baselineStaticNodeFingerprints: $this->context?->baselineStaticNodeFingerprints ?? [],
			baselineStorageCodes: $this->context?->baselineStorageCodes ?? [],
		);
	}

	/**
	 * Blocks that may carry a connection at all: a frame overlay owns no ports, and the connections validator
	 * refuses an edge touching one, so counting it as a place the flow can start at would invent an entry point.
	 *
	 * @return list<string>
	 */
	private function connectableBlockIds(): array
	{
		$blockIds = [];
		foreach ($this->validBlocks as $block)
		{
			/** @var AgentBlock $block */
			if (!in_array($block->id, $this->frameBlockIds, true))
			{
				$blockIds[] = $block->id;
			}
		}

		return $blockIds;
	}

	/**
	 * Collects the storage codes this template owns for the graph under validation (ALG-11): the creators of the
	 * published baseline plus the create nodes of this very graph the policy admits. Their settings are parsed
	 * by a probe instance of the same block validator, so both passes judge one and the same value; the probe's
	 * errors are dropped and its black list stays empty - the verdict on a node, duplicate ids included, belongs
	 * to the main pass alone.
	 *
	 * The policy is asked with the ORIGINAL context, never with the growing set, or two forged create nodes
	 * would legitimise each other. The enriched copy is assembled once and never mutated afterwards, or the
	 * verdict would start depending on the order of the blocks.
	 */
	private function contextWithOwnedStorageCodes(
		array $blocks,
		DocumentDescription $documentType,
		string $path,
	): ?ValidationContext
	{
		$context = $this->context;
		if ($context === null)
		{
			return null;
		}

		$ownedCodes = $context->baselineCreatorStorageCodes;
		$probe = null;
		foreach ($blocks as $block)
		{
			$type = is_array($block) ? ($block['type'] ?? null) : null;
			if (!is_string($type) || !StorageNodeClassifier::isStorageCreator($type))
			{
				continue;
			}

			$probe ??= $this->createBlockValidator($context);
			if (!$probe->validate($block, $documentType, [], $path)->isSuccess())
			{
				continue;
			}

			$validBlock = $probe->getValidBlock();
			if ($validBlock === null)
			{
				continue;
			}

			$verdict = $this->createStorageNodePolicy->admit($validBlock->type, $validBlock->settings, $context);
			$code = StorageNodeClassifier::extractStorageCode($validBlock->settings);
			if ($verdict->allowed && $code !== null && $code !== '')
			{
				$ownedCodes[] = $code;
				$this->collectDeclaredStorageColumns($code, $validBlock->settings);
			}
		}

		return $context->withOwnedStorageCodes(array_values(array_unique($ownedCodes)));
	}

	/**
	 * The columns one admitted create node declares, kept for the code it declares them for. Two creators of one
	 * code that disagree leave the code holding NOTHING - not the columns of either, and not the ones the
	 * published version declares: the graph has no single answer to what the storage is, and
	 * {@see self::validateStorageOrder()} refuses it as `StorageCreatorConflict`. Judging a value map against a
	 * contract picked from two would answer one refusal with a second, dependent one.
	 *
	 * Agreement is read through the very function the order check compares two creators by
	 * ({@see StorageNodeFingerprint::columnContractOf()}), so "one contract" means the same thing in both.
	 */
	private function collectDeclaredStorageColumns(string $code, AgentSettingCollection $settings): void
	{
		$normalized = StorageReferenceGuard::normalizeCode($code);
		$contract = StorageNodeFingerprint::columnContractOf($settings);

		if (!array_key_exists($normalized, $this->graphStorageColumns))
		{
			$this->graphStorageColumns[$normalized] = StorageNodeClassifier::extractStorageColumns($settings);
			$this->graphStorageColumnContracts[$normalized] = $contract;

			return;
		}

		if (($this->graphStorageColumnContracts[$normalized] ?? null) !== $contract)
		{
			$this->graphStorageColumns[$normalized] = null;
		}
	}

	/**
	 * The one catalog of the run. The baseline types are the same on every context this validator builds (the
	 * enriched one is a copy), so the catalogs keyed on them stay memoised for the whole run.
	 */
	private function blockDescriptionCache(?ValidationContext $context): BlockDescriptionCache
	{
		return $this->blockDescriptionCache ??= new BlockDescriptionCache(
			source: $this->source,
			baselineBlockTypes: $context?->baselineBlockTypes ?? [],
		);
	}

	private function createBlockValidator(?ValidationContext $context): AgentBlockValidator
	{
		$baselineBlockTypes = $context?->baselineBlockTypes ?? [];
		$blockDescriptionCache = $this->blockDescriptionCache($context);
		$this->metadataResolver ??= new AgentBlockMetadataResolver(
			source: $this->source,
			baselineBlockTypes: $baselineBlockTypes,
		);
		$this->restrictedSettings ??= new RestrictedSettingRegistry();

		return new AgentBlockValidator(
			$blockDescriptionCache,
			metadataResolver: $this->metadataResolver,
			context: $context,
			restrictedSettings: $this->restrictedSettings,
			ancestorIndex: $this->ancestorIndex,
			outputResolver: $this->outputResolver,
			storageColumns: $this->storageColumns,
		);
	}

	public function getValidBlocks(): AgentBlockCollection
	{
		return $this->validBlocks;
	}

	/**
	 * @return list<string>
	 */
	public function getBlockIds(): array
	{
		return $this->blockIds;
	}

	/**
	 * @return list<string> ids of the valid frame overlays (empty unless a REST agent sent frames)
	 */
	public function getFrameBlockIds(): array
	{
		return $this->frameBlockIds;
	}

	/**
	 * @return array<string, array{input: list<string>, output: list<string>, dynamic?: bool}>
	 */
	public function getBlockPortsMap(): array
	{
		return $this->blockPortsMap;
	}

	/**
	 * @return array<string, list<string>> loop-back input port ids per block id (loop nodes only)
	 */
	public function getBlockLoopbackInputPortsMap(): array
	{
		return $this->blockLoopbackInputPortsMap;
	}

	/**
	 * @return list<SetupConstantDeclaration> constants the admitted wizard nodes outside the baseline declare
	 *         (empty unless the graph carries such a node)
	 */
	public function getSetupConstantDeclarations(): array
	{
		return $this->setupConstantDeclarations;
	}

	/**
	 * Risks of the last run that did not refuse the graph (DTO-02). Whether they reach the agent at all is the
	 * caller's answer, not this one's: the profile that emits no warning is read where the report is assembled
	 * ({@see SaveWorkflowValidator::getWarnings()}).
	 *
	 * @return list<Error>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}
}

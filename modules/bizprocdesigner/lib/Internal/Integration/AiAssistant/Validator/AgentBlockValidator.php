<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Public\Service\Storage\StorageReference;
use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Graph\AncestorIndex;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Sanitizer\BbCodeSanitizer;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockMetadataResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockOutputResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageColumnSchemaResolver;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeClassifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeFingerprint;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

final class AgentBlockValidator
{
	// Sanitary length caps, kept in sync with the tool input schema (SaveWorkflowTool::getInputSchema).
	private const TITLE_MAX_LENGTH = 255;
	private const DESCRIPTION_MAX_LENGTH = 1000;
	private const PRESET_ID_MAX_LENGTH = 255;

	/** Frame background/border colours the editor offers (FRAME_COLOR_NAMES in the chart extension). */
	private const ALLOWED_FRAME_COLOR_NAMES = ['grey', 'orange', 'green', 'blue', 'purple', 'pink'];

	/** Fields only a frame overlay carries, in the order the block resource declares them {@see validateFrame}. */
	private const FRAME_ONLY_FIELDS = ['memberBlockIds', 'frameColorName', 'frameContent'];

	/** Opening of a bizproc reference; text without it never reaches the parse {@see judgeExpressionsIn}. */
	private const EXPRESSION_OPENING = '{=';

	private readonly BlockDescriptionCache $blockDescriptionCache;
	private readonly AgentBlockSettingsValidator $settingsValidator;
	private readonly AgentBlockMetadataResolver $metadataResolver;
	private readonly AgentComplexRulesValidator $complexRulesValidator;
	/**
	 * The origin of the write, kept for the composition of the CATALOG alone: the reverse REST path keeps EXCLUDED
	 * activities visible so a system AI agent's own nodes still resolve a system name. That is a visibility filter
	 * and not a relaxed check, so it stays on the source while every strictness question below asks the profile
	 * (TPL-02).
	 */
	private readonly RequestSource $source;
	private readonly ValidationProfile $profile;
	private readonly ?ValidationContext $context;
	private readonly StorageReferenceGuard $storageReferenceGuard;
	private readonly RestrictedSettingRegistry $restrictedSettings;

	/**
	 * The graph the expression references of a block are judged against (BRIEF § 4.2), built once per run by the
	 * pass that owns the whole graph ({@see AgentBlocksValidator}). Absent - a validator built for one block
	 * alone - leaves the references unjudged: a block on its own knows no ancestors, and inventing an answer
	 * would refuse every reference of it.
	 */
	private readonly ?AncestorIndex $ancestorIndex;
	private readonly ?BlockOutputResolver $outputResolver;

	/**
	 * Reads the columns of the storage a consumer node addresses, so the keys of its dynamic value map are judged
	 * here as they are in the catalog (BRIEF § 3.2, § 3.4). Built once per run by the pass that owns the whole
	 * graph ({@see AgentBlocksValidator}) - the first source of the columns is a create node of that graph, which
	 * a validator built for one block alone cannot see. Absent leaves the keys unjudged, exactly as before.
	 */
	private readonly ?StorageColumnSchemaResolver $storageColumns;

	private ?StorageAuthorizationContext $storageAuthorizationContext = null;
	private ?AgentBlock $validBlock = null;
	private ?BlockTypeDetail $validBlockDetail = null;
	private ?string $id = null;

	/**
	 * Risks named on the block of the last run without refusing it (DTO-02), read by the graph pass and carried
	 * from there to the report ({@see AgentBlocksValidator::getWarnings()}).
	 *
	 * @var list<Error>
	 */
	private array $warnings = [];

	public function __construct(
		BlockDescriptionCache $blockDescriptionCache,
		?AgentBlockSettingsValidator $settingsValidator = null,
		?AgentBlockMetadataResolver $metadataResolver = null,
		?AgentComplexRulesValidator $complexRulesValidator = null,
		?ValidationContext $context = null,
		?StorageReferenceGuard $storageReferenceGuard = null,
		?RestrictedSettingRegistry $restrictedSettings = null,
		?AncestorIndex $ancestorIndex = null,
		?BlockOutputResolver $outputResolver = null,
		?StorageColumnSchemaResolver $storageColumns = null,
	) {
		$this->ancestorIndex = $ancestorIndex;
		$this->outputResolver = $outputResolver;
		$this->storageColumns = $storageColumns;
		$this->blockDescriptionCache = $blockDescriptionCache;
		$this->settingsValidator = $settingsValidator ?? new AgentBlockSettingsValidator(context: $context);
		$this->context = $context;
		// Fail-closed like ValidationContext itself: no context at all means the least permissive source, and the
		// profile of the internal assistant.
		$this->source = $context?->source ?? RequestSource::Marta;
		$this->profile = ValidationProfile::of($context);
		// Source-aware resolver: the reverse REST path keeps EXCLUDED activities so a system AI agent's own
		// nodes still resolve a system name.
		$this->metadataResolver = $metadataResolver ?? new AgentBlockMetadataResolver(source: $this->source);
		$this->storageReferenceGuard = $storageReferenceGuard ?? new StorageReferenceGuard();
		$this->restrictedSettings = $restrictedSettings ?? new RestrictedSettingRegistry();
		// The nested level is judged by the same barrier as this one, so it is handed the same context and the same
		// memoized registry rather than resolving restricted names of its own.
		$this->complexRulesValidator = $complexRulesValidator
			?? new AgentComplexRulesValidator($context, $this->restrictedSettings)
		;
	}

	public function validate(
		mixed $block,
		DocumentDescription $documentType,
		array $blackListIds = [],
		string $path = '',
	): Result
	{
		$this->warnings = [];
		if (!is_array($block))
		{
			return (new Result())->addError(GraphError::at(
				$path,
				"$path should be object",
				GraphErrorCode::GraphElementInvalid,
			));
		}
		$this->validBlock = null;
		$this->validBlockDetail = null;
		$this->id = null;

		// Judged before the branches part, because every one of them carries settings an expression may stand in
		// and the question is about the graph rather than about the kind of node asking.
		$expressionResult = $this->validateExpressionReferences($block, $path);

		$id = $block['id'] ?? null;
		$title = $block['title'] ?? null;
		$description = $block['description'] ?? null;
		$presetId = $block['presetId'] ?? null;
		$type = $block['type'] ?? null;
		$settings = $block['settings'] ?? null;

		// The frame overlay is not an activity with ports/dialog, so it bypasses the catalog lookup entirely
		// and validates its own shape instead. Only the external contour may build frames; under the internal
		// profile this branch is skipped and the frame falls through to validateType(), where it is rejected as
		// an incorrect type.
		if ($this->profile->allowsFrameOverlay() && FrameBlockMatcher::matches($type, $presetId))
		{
			$frameResult = $this->validateFrame($block, $id, $title, $type, $presetId, $path, $blackListIds);
			$frameResult->addErrors($expressionResult->getErrors());
			$this->id = $id;

			return $frameResult;
		}

		// An agent entry node declares an empty settings schema, so its settings bypass the schema check and are
		// carried through verbatim. Judged the same on every entry: the node is one and the same wherever it comes
		// from. The branch is entered on the type alone - whether that type applies to this document type is not
		// checked here, so the node is accepted in a template of any document type.
		// The same branch answers a node the catalog does not describe at all but the PUBLISHED version carries
		// under this very id and type: template.get emits it (a manual start trigger is the everyday one), the
		// agent is told to send it back untouched, and refusing it as an unknown type would cost every template
		// built that way its round-trip (AC-35). Admission rides on the published version alone - never on the
		// draft, which the agent writes itself ({@see self::carriesUndescribedPublishedNode()}) - and the node has
		// to come back verbatim, which {@see self::validateVerbatimStartTrigger()} checks.
		if (AgentStartTriggerMatcher::matches($type) || $this->carriesUndescribedPublishedNode($id, $type, $documentType, $presetId))
		{
			$triggerResult = $this->validateVerbatimStartTrigger(
				$block,
				$documentType,
				$id,
				$title,
				$type,
				$presetId,
				$path,
				$blackListIds,
			);
			$triggerResult->addErrors($expressionResult->getErrors());
			$this->id = $id;

			return $triggerResult;
		}

		$result = new Result();
		$result->addErrors($expressionResult->getErrors());
		$result->addErrors($this->validateId($id, $path, $blackListIds, $type)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());
		$result->addErrors($this->validateDescription($description, $path)->getErrors());
		$result->addErrors($this->validatePresetId($presetId, $path)->getErrors());
		$typeValidationResult = $this->validateType($type, $path, $documentType, is_string($presetId) ? $presetId : null);
		$blockDetail = $typeValidationResult instanceof BlockSettingsResult ? $typeValidationResult->blockDetail : null;
		$result->addErrors($typeValidationResult->getErrors());
		$storageContextFailure = null;
		if ($blockDetail !== null && is_string($type))
		{
			$described = $this->describedForItsStorage(
				$blockDetail,
				$type,
				is_string($presetId) && $presetId !== '' ? $presetId : null,
				$documentType,
				$settings,
			);
			if ($described === null)
			{
				// The server could not describe the block for the storage it names, so the keys of its value map
				// were judged on nothing. Answered as a failure and never as the description built without the
				// context, which would publish the map as one that admits any key (BRIEF § 1.4, AC-37). It joins
				// the settings errors so that a failure caused by a reference the guard denies is dropped there
				// with the rest of the dependent ones.
				$storageContextFailure = GraphError::at(
					"{$path}.settings",
					"{$path}.settings cannot be checked: the server could not describe this block for the storage"
					. ' it addresses',
					GraphErrorCode::ContextResolutionFailed,
				);
			}
			else
			{
				$blockDetail = $described;
			}
		}

		if ($blockDetail !== null)
		{
			try
			{
				$result->addErrors($this->validateSystemName($type, $presetId, $path)->getErrors());
				if (is_string($type) && is_string($presetId) && $presetId !== '' && !$this->metadataResolver->hasPreset($type, $presetId))
				{
					$result->addError(GraphError::at(
						"{$path}.presetId",
						"{$path}.presetId is not a valid preset for this block type",
						GraphErrorCode::PresetIdInvalid,
					));
				}
			}
			catch (ServiceNotFoundException | CircularDependencyException | ObjectNotFoundException)
			{
				$result->addError(GraphError::at(
					"{$path}.type",
					"{$path}.type block system name cannot be resolved (catalog unavailable)",
				));
			}
		}
		$settingValidationResult = $this->settingsValidator->validate(
			$settings,
			"$path.settings",
			$blockDetail,
			is_string($type) ? $type : null,
			// The very preset $blockDetail - and thus the option lists inside it - was resolved with, so the
			// carried-value passthrough of the option check can only unlock a value inside the variant that
			// carried it. Normalised as above: the empty preset is no preset.
			is_string($presetId) && $presetId !== '' ? $presetId : null,
			$path,
			// The identity the requiredness exemption of an unchanged published block is matched by (ALG-02).
			// An id that is not a string never matches anything, and its own refusal is reported above.
			blockId: is_string($id) ? $id : null,
			allowPublishedExpressionKeys: $this->carriesPublishedWriterUnchanged($block),
		);
		// A storage consumer is authorised against the published baseline once its type is known (ALG-02).
		// create/setuptemplate are out of scope here - AgentBlocksValidator checks them against the immutable
		// baseline instead, so a forged create carrying a token code cannot slip through the token branch.
		// Resolved here rather than where it is reported, because a denial of the reference decides what the
		// settings check above is allowed to say about the context built on that very reference.
		$storageAuthorizationResult = new Result();
		if ($blockDetail !== null && is_string($type) && StorageNodeClassifier::isConsumer($type))
		{
			$storageAuthorizationResult = $this->authorizeStorageReference($type, $path);
		}

		if ($storageContextFailure !== null)
		{
			$settingValidationResult->addError($storageContextFailure);
		}

		$result->addErrors(
			self::withoutDependentContextFailures($settingValidationResult, $storageAuthorizationResult),
		);

		$validRules = null;
		if ($blockDetail !== null)
		{
			if ($blockDetail->complexDetail !== null)
			{
				$rulesValidationResult = $this->complexRulesValidator->validate(
					$block['rules'] ?? null,
					$blockDetail->complexDetail,
					"$path.rules",
					$documentType,
					is_string($type) ? $type : null,
				);
				$result->addErrors($rulesValidationResult->getErrors());
				$validRules = $this->complexRulesValidator->getValidRules();
			}
			elseif (array_key_exists('rules', $block) && $block['rules'] !== null)
			{
				$result->addError(GraphError::at(
					"$path.rules",
					"$path.rules is only allowed for complex nodes",
					GraphErrorCode::GraphElementNotAllowed,
				));
			}
		}

		// Same refusal as rules on a plain block, for the same reason: the overlay fields belong to a frame,
		// and dropping them without a word leaves the caller believing it grouped and annotated blocks that
		// stayed plain.
		foreach (self::FRAME_ONLY_FIELDS as $frameField)
		{
			if (array_key_exists($frameField, $block) && $block[$frameField] !== null)
			{
				$result->addError(GraphError::at(
					"$path.$frameField",
					"$path.$frameField is only allowed for frame blocks",
					GraphErrorCode::GraphElementNotAllowed,
				));
			}
		}

		$result->addErrors($storageAuthorizationResult->getErrors());

		if ($blockDetail !== null && is_string($type))
		{
			$result->addErrors(
				$this->authorizeRestrictedSettings(
					$type,
					$documentType,
					$this->settingsValidator->getValidSettings() ?? new AgentSettingCollection(),
					$path,
				)->getErrors(),
			);
		}

		if ($result->isSuccess() && $this->settingsValidator->getValidSettings())
		{
			$this->validBlock = new AgentBlock(
				$type,
				$title ?? '',
				$id,
				$this->settingsValidator->getValidSettings(),
				$description,
				is_string($presetId) && $presetId !== '' ? $presetId : null,
				$validRules,
			);
			$this->validBlockDetail = $blockDetail;
		}

		$this->id = $id;

		return $result;
	}

	/**
	 * Every substitution of the block the runtime reads as an address of another block, judged against the graph
	 * this entry submitted (BRIEF § 4.2). What the contour writes today is accepted whole and fails only while
	 * the process runs, where the agent never sees it: a value read from a block that is not in the graph, that
	 * runs after the reader, or that has no such output at all.
	 *
	 * What counts as an address of a block is not decided here and must not be: the runtime answers it
	 * ({@see SourceType::getObjectSourceType()}), and its reserved objects - Document, Variable, Workflow and the
	 * rest - stay its own responsibility. The list of them is pinned by a guard of its own
	 * ({@see \Bitrix\Bizproc\Tests\Lib\Workflow\Template\SourceTypeTest}), so a name the runtime adds cannot start
	 * being reported here as a block that does not exist.
	 *
	 * The settings of the block are the whole surface. The nested constructions of a complex node are judged by
	 * {@see AgentComplexRulesValidator} and are deliberately left alone here: a rule of such a node addresses the
	 * results of the filter of its OWN host by the host's id
	 * ({@see \Bitrix\BizprocDesigner\Internal\Service\NodeFilter\FoundItemsService}), and the ancestor rule read
	 * over that surface without the exception would refuse a published template outright.
	 *
	 * The graph is not the whole of what an expression may address, and that is why the block list alone cannot
	 * decide "there is no such block". A complex node runs its sub-actions as children of its own, and the
	 * runtime resolves a reference to one of them by name ({@see \CBPWorkflow::getActivityByName()}) - while the
	 * projection the agent is handed carries no name for them at all. The shipped system agents address exactly
	 * such a child, so the published version is asked whether an unresolved id is one the SERVER wrote - a name it
	 * carries anywhere in its tree ({@see ValidationContext::$baselinePublishedNames}) or an id it addresses in an
	 * expression of its own ({@see BaselineProjection::addressedBlockIds()}); only an id nobody but the agent could
	 * have written is refused.
	 */
	private function validateExpressionReferences(array $block, string $path): Result
	{
		$result = new Result();
		if (
			!$this->profile->enforcesExpressionReferences()
			|| $this->ancestorIndex === null
			|| $this->outputResolver === null
		)
		{
			return $result;
		}

		$consumerId = $block['id'] ?? null;
		if (!is_string($consumerId) || $consumerId === '')
		{
			// A block whose own id is refused names no place in the flow, so it has no ancestors to be held to.
			return $result;
		}

		$warnedBlockIds = [];
		$this->collectExpressionReferences(
			$block['settings'] ?? null,
			"{$path}.settings",
			$consumerId,
			$result,
			$warnedBlockIds,
		);

		return $result;
	}

	/**
	 * Walks a submitted value to every string it holds. Both the values and the KEYS of a map are read, the way
	 * the runtime reads them: an activity writing under an addressed target keeps that target as the key of the
	 * row ({@see \Bitrix\Bizproc\Public\Service\Template\ExpressionReferenceScanner}).
	 *
	 * @param array<string, true> $warnedBlockIds ids already warned about within this block
	 */
	private function collectExpressionReferences(
		mixed $value,
		string $path,
		string $consumerId,
		Result $result,
		array &$warnedBlockIds,
	): void
	{
		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				$rowPath = "{$path}.{$key}";
				$this->judgeExpressionsIn((string)$key, $rowPath, $consumerId, $result, $warnedBlockIds);
				$this->collectExpressionReferences($item, $rowPath, $consumerId, $result, $warnedBlockIds);
			}

			return;
		}

		if (is_string($value))
		{
			$this->judgeExpressionsIn($value, $path, $consumerId, $result, $warnedBlockIds);
		}
	}

	/**
	 * The six outcomes of one string, in the order of BRIEF § 4.2. Every reference the text holds is judged, not
	 * only a text that IS one: {@see \CBPActivity::findExpressions()} is the inline parse, so a reference nested
	 * inside a calculated `{{=...}}` expression is read exactly as the runtime reads it.
	 *
	 * @param array<string, true> $warnedBlockIds
	 */
	private function judgeExpressionsIn(
		string $value,
		string $path,
		string $consumerId,
		Result $result,
		array &$warnedBlockIds,
	): void
	{
		if (!str_contains($value, self::EXPRESSION_OPENING))
		{
			return;
		}

		foreach (\CBPActivity::findExpressions($value) as $expression)
		{
			$source = SourceType::getObjectSourceType((string)$expression['object'], (string)$expression['field']);
			if (($source[0] ?? null) !== SourceType::Activity)
			{
				continue;
			}

			$referencedId = (string)($source[1] ?? '');
			if (!$this->ancestorIndex->hasBlock($referencedId))
			{
				// An id the published version itself addresses is the server's own writing and not the agent's
				// invention: the child of a complex node's sub-action is one of those, and it is no block of any
				// graph. Refusing it would cost every template built that way its round-trip, so only an id
				// nobody but the agent could have written is refused.
				if (!$this->isAddressedByPublishedVersion($referencedId))
				{
					$result->addError(GraphError::at(
						$path,
						"{$path} addresses a block the graph does not carry",
						GraphErrorCode::ExpressionBlockUnknown,
					));
				}

				continue;
			}

			// A block addressing itself reads the results of its own node filter, which the runtime resolves
			// inside the very node ({@see \Bitrix\BizprocDesigner\Internal\Service\NodeFilter\FoundItemsService});
			// that is not a value produced before the node and is not held to the ancestor rule.
			if ($referencedId !== $consumerId && !$this->ancestorIndex->isAncestorOf($referencedId, $consumerId))
			{
				$result->addError(GraphError::at(
					$path,
					"{$path} addresses a block that does not run before this one",
					GraphErrorCode::ExpressionBlockNotAncestor,
				));

				continue;
			}

			$this->judgeAddressedOutput(
				$referencedId,
				(string)($source[2] ?? ''),
				$path,
				$result,
				$warnedBlockIds,
			);
		}
	}

	/**
	 * @param array<string, true> $warnedBlockIds
	 */
	private function judgeAddressedOutput(
		string $referencedId,
		string $output,
		string $path,
		Result $result,
		array &$warnedBlockIds,
	): void
	{
		$outputs = $this->outputResolver->outputsOf($referencedId);
		if ($outputs !== null)
		{
			// An empty package is an answer like any other: a block that produces nothing can be addressed for
			// nothing, and the allowed list travels along so the agent is not left guessing what it may read.
			if (!self::namesAnOutput($output, $outputs))
			{
				$result->addError(GraphError::at(
					$path,
					"{$path} addresses an output the block it names does not have",
					GraphErrorCode::ExpressionOutputUnknown,
					['allowedOutputs' => $outputs],
				));
			}

			return;
		}

		if ($this->outputResolver->hasFailed($referencedId))
		{
			$result->addError(GraphError::at(
				$path,
				"{$path} the outputs of the block it addresses could not be resolved",
				GraphErrorCode::ContextResolutionFailed,
			));

			return;
		}

		// Only the perimeter of the package is unknown, and this reference is inside the half the type declares
		// in its own description: a loop node publishes Key and Value in the catalog, so warning about them would
		// put a risk on every graph that carries a loop and teach the agent to ignore the channel.
		if (self::namesAnOutput($output, $this->outputResolver->knownOutputsOf($referencedId)))
		{
			return;
		}

		// The type has declared that part of its outputs is not in its description, so nothing is claimed about
		// this reference - and the risk is named once per addressed block, however many times it is read.
		if (!isset($warnedBlockIds[$referencedId]))
		{
			$warnedBlockIds[$referencedId] = true;
			$this->warnings[] = GraphError::at(
				$path,
				"{$path} the outputs of the block it addresses cannot be listed before the graph is saved",
				GraphErrorCode::ExpressionOutputsUnknown,
			);
		}
	}

	/**
	 * Whether the id is one the SERVER wrote rather than one the agent invented: the published version either
	 * addresses it in an expression of its own, or carries a node under that name somewhere in its tree
	 * ({@see ValidationContext::$baselinePublishedNames}). The second reading is the honest one - it proves the
	 * node exists - and it is what lets a NEW expression address the child of a sub-action, which the projection
	 * the agent is handed does not name at all. The first stays because it costs nothing and answers a template
	 * whose expression outlived the node it names.
	 *
	 * Both are read once per run and kept, so a graph of 256 blocks asks the published version for them once.
	 */
	private function isAddressedByPublishedVersion(string $blockId): bool
	{
		return isset(($this->context?->baseline->addressedBlockIds() ?? [])[$blockId])
			|| isset(($this->context?->baselinePublishedNames ?? [])[$blockId])
		;
	}

	/**
	 * Whether the addressed field names an output of the block. A dotted field names a field of a DOCUMENT output
	 * rather than an output of its own ({@see \Bitrix\Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter}), so
	 * the part before the first dot is what has to be an output there.
	 *
	 * @param list<string> $outputs
	 */
	private static function namesAnOutput(string $field, array $outputs): bool
	{
		if (in_array($field, $outputs, true))
		{
			return true;
		}

		$dot = strpos($field, '.');

		return $dot !== false && in_array(substr($field, 0, $dot), $outputs, true);
	}

	/**
	 * The errors of the settings check with the ones that only restate a refusal already given left out. The
	 * keys of a dynamic map are computed from a reference the policy has just denied, so they cannot be
	 * resolved either; answering one denial twice - as the policy refusal and again as a failure to assemble
	 * the context - would send the agent looking for a second problem it does not have (BRIEF § 1.4).
	 *
	 * @return list<Error>
	 */
	private static function withoutDependentContextFailures(Result $settings, Result $storage): array
	{
		if ($storage->isSuccess())
		{
			return $settings->getErrors();
		}

		$errors = [];
		foreach ($settings->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			$errorCode = is_array($customData) ? ($customData['errorCode'] ?? null) : null;
			if ($errorCode !== GraphErrorCode::ContextResolutionFailed->value)
			{
				$errors[] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Validates a frame overlay's own shape (id + styling allowlist), sanitises its free-text styling and
	 * builds the valid frame block. Membership (memberBlockIds) and member connectivity are delegated to
	 * {@see AgentFrameMembershipValidator}, which runs once over the whole graph in the workflow validator.
	 */
	private function validateFrame(
		array $block,
		mixed $id,
		mixed $title,
		mixed $type,
		mixed $presetId,
		string $path,
		array $blackListIds,
	): Result
	{
		$result = new Result();
		$result->addErrors($this->validateId($id, $path, $blackListIds, $type)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());

		$frameColorName = $block['frameColorName'] ?? null;
		$result->addErrors($this->validateFrameColorName($frameColorName, $path)->getErrors());

		$frameContent = $block['frameContent'] ?? null;
		$result->addErrors(
			$this->validateOptionalText(
				$frameContent,
				"{$path}.frameContent",
				BbCodeSanitizer::MAX_FRAME_CONTENT_LENGTH,
			)->getErrors(),
		);

		if ($result->isSuccess())
		{
			$this->validBlock = new AgentBlock(
				type: (string)$type,
				// The frontend escapes the title at render time, so encoding it here would double-escape it
				// and break round-trip idempotency.
				title: is_string($title) ? $title : '',
				id: (string)$id,
				settings: new AgentSettingCollection(),
				description: null,
				presetId: (string)$presetId,
				rules: null,
				returnProperties: [],
				memberBlockIds: $this->parseMemberBlockIds($block),
				frameColorName: is_string($frameColorName) ? $frameColorName : null,
				frameContent: is_string($frameContent) ? BbCodeSanitizer::sanitizeFrameContent($frameContent) : null,
			);
		}

		return $result;
	}

	/**
	 * Validates an agent entry node, whose settings schema is empty by design: the old-format template converter
	 * writes presentational settings (Icon, ColorIndex) onto every trigger, so a schema check would refuse them
	 * and cost the round-trip its idempotency. Hence the settings are carried through verbatim while the other
	 * fields keep the ordinary node's strictness, so nothing is silently dropped on save: presetId and rules are
	 * refused (no preset, no complex sub-graph), which template.get honours by emitting both as null. Verbatim is
	 * not unchecked either: a setting the activity itself declares restricted is held to the graph the server wrote
	 * by the same barrier the ordinary path applies (ALG-04), so the empty schema buys no exemption from it.
	 *
	 * The node stays a connectable graph node (kept out of frameBlockIds, so its outgoing edge goes through the
	 * connections validator). Its catalog passport is looked up for the port topology alone; having none is a
	 * legal state that only keeps the node out of the ports map.
	 */
	private function validateVerbatimStartTrigger(
		array $block,
		DocumentDescription $documentType,
		mixed $id,
		mixed $title,
		mixed $type,
		mixed $presetId,
		string $path,
		array $blackListIds,
	): Result
	{
		$description = $block['description'] ?? null;

		$result = new Result();
		$result->addErrors($this->validateId($id, $path, $blackListIds, $type)->getErrors());
		$result->addErrors($this->validateTitle($title, $path)->getErrors());
		$result->addErrors($this->validateDescription($description, $path)->getErrors());
		$result->addErrors($this->validatePresetId($presetId, $path)->getErrors());

		if (is_string($presetId) && $presetId !== '')
		{
			$result->addError(GraphError::at(
				"{$path}.presetId",
				"{$path}.presetId is not a valid preset for this block type",
				GraphErrorCode::PresetIdInvalid,
			));
		}

		if (array_key_exists('rules', $block) && $block['rules'] !== null)
		{
			$result->addError(GraphError::at(
				"$path.rules",
				"$path.rules is only allowed for complex nodes",
				GraphErrorCode::GraphElementNotAllowed,
			));
		}

		$settings = $this->collectPassthroughSettings($block['settings'] ?? null, "{$path}.settings", $result);
		$result->addErrors(
			$this->authorizeRestrictedSettings((string)$type, $documentType, $settings, $path)->getErrors(),
		);
		// The catalog is keyed by the lower-cased type while the matcher above takes the type in any case, so the
		// lookup normalises it. The block below keeps the type as it arrived - the server accepts back exactly what
		// it emitted.
		$blockDetail = $this->resolveBlockDetailOrNull(
			mb_strtolower((string)$type),
			$documentType,
			is_string($presetId) ? $presetId : null,
		);

		$carriedBlock = new AgentBlock(
			type: (string)$type,
			title: is_string($title) ? $title : '',
			id: (string)$id,
			settings: $settings,
			description: is_string($description) ? $description : null,
		);

		// A node admitted for being published rather than for being an agent entry node owes the stricter half of
		// the deal: it is carried through, not authored, so anything but the published instance is refused here.
		// The two agent start triggers are held to the same rule one level up, on the graph pass, where their
		// published instances are indexed ({@see AgentBlocksValidator::validatePublishedReferenceScope()}).
		if (!AgentStartTriggerMatcher::matches($type) && $result->isSuccess())
		{
			$published = $this->context?->baseline->match((string)$id, (string)$type);
			if (
				$published === null
				|| AgentStartTriggerMatcher::referenceFingerprint($published)
					!== AgentStartTriggerMatcher::referenceFingerprint($carriedBlock)
			)
			{
				$result->addError(GraphError::at(
					$path,
					"{$path} must match the published template node exactly: a block of a type the catalog does not"
					. ' describe is carried through rather than authored',
					GraphErrorCode::ImmutableNodePolicyViolation,
				));
			}
		}

		if ($result->isSuccess())
		{
			$this->validBlock = $carriedBlock;
			$this->validBlockDetail = $blockDetail;
		}

		return $result;
	}

	/**
	 * Whether the catalog cannot describe this type while the PUBLISHED version carries a block under this very id
	 * and type. Such a node is what template.get emits and what the agent is told to send back untouched - a manual
	 * start trigger is the everyday case - so refusing it as an unknown type would cost every template built that
	 * way its round-trip (AC-35).
	 *
	 * The published version alone admits it: the draft is what the agent writes itself, and reading admission off
	 * the draft would let a graph authorise its own block. The catalog lookup is asked last, being the expensive
	 * half of the answer.
	 */
	private function carriesUndescribedPublishedNode(
		mixed $id,
		mixed $type,
		DocumentDescription $documentType,
		mixed $presetId,
	): bool
	{
		if (!is_string($id) || $id === '' || !is_string($type) || $type === '')
		{
			return false;
		}

		if ($this->context?->baseline->match($id, $type) === null)
		{
			return false;
		}

		return $this->resolveBlockDetailOrNull(
			mb_strtolower($type),
			$documentType,
			is_string($presetId) && $presetId !== '' ? $presetId : null,
		) === null;
	}

	/**
	 * Resolves the catalog passport of a verbatim node, needed for its port topology alone. Having none - an
	 * unreachable catalog included - is a legal state rather than a validation error: the node simply stays out of
	 * the ports map, so a template the server itself emitted is never refused over a service the request cannot
	 * reach. The lookup itself is inside the try, unlike on the ordinary path, where the cache is consulted before
	 * the guarded block.
	 */
	private function resolveBlockDetailOrNull(
		string $type,
		DocumentDescription $documentType,
		?string $presetId = null,
	): ?BlockTypeDetail
	{
		try
		{
			return $this->blockDescriptionCache->get($type, $documentType, $presetId);
		}
		catch (ServiceNotFoundException | CircularDependencyException | ObjectNotFoundException)
		{
			return null;
		}
	}

	/**
	 * Builds the setting collection of a verbatim node: every setting is taken as it is, so nothing the template
	 * persisted is lost. Only the per-setting shape is checked - the empty schema of such a node declares no name
	 * to cross-check a setting against and no type to coerce its value to.
	 */
	private function collectPassthroughSettings(mixed $settings, string $path, Result $result): AgentSettingCollection
	{
		$collection = new AgentSettingCollection();
		if ($settings === null)
		{
			return $collection;
		}

		if (!is_array($settings))
		{
			$result->addError(GraphError::at($path, "{$path} should be array", GraphErrorCode::SettingValueInvalid));

			return $collection;
		}

		$settingValidator = new AgentBlockSettingValidator();
		foreach ($settings as $key => $rawSetting)
		{
			$settingResult = $settingValidator->validate($rawSetting, "{$path}.{$key}", null);
			$result->addErrors($settingResult->getErrors());

			$validSetting = $settingValidator->getValidSetting();
			if ($settingResult->isSuccess() && $validSetting !== null)
			{
				$collection->add($validSetting);
			}
		}

		return $collection;
	}

	private function validateFrameColorName(mixed $frameColorName, string $path): Result
	{
		if ($frameColorName === null)
		{
			return new Result();
		}

		$colorPath = "{$path}.frameColorName";

		if (!is_string($frameColorName))
		{
			return (new Result())->addError(GraphError::at(
				$colorPath,
				"{$colorPath} should be string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		if (!in_array($frameColorName, self::ALLOWED_FRAME_COLOR_NAMES, true))
		{
			// The allowed names are nowhere else in the contract - the frame carries an empty settings schema,
			// so the catalog has no field to publish them through - and the refusal is the only place a caller
			// can learn them.
			return (new Result())->addError(GraphError::at(
				$colorPath,
				"{$colorPath} is not an allowed frame colour. Allowed values: "
					. implode(', ', self::ALLOWED_FRAME_COLOR_NAMES),
				GraphErrorCode::GraphElementInvalid,
			));
		}

		return new Result();
	}

	/**
	 * @return list<string>
	 */
	private function parseMemberBlockIds(array $block): array
	{
		$memberBlockIds = [];
		foreach ((array)($block['memberBlockIds'] ?? []) as $memberId)
		{
			if (is_string($memberId) || is_int($memberId))
			{
				$memberBlockIds[] = (string)$memberId;
			}
		}

		return $memberBlockIds;
	}

	/**
	 * The identifier of a block, which is also the system name the saved activity is addressed by (BRIEF § 4.3).
	 *
	 * A NEW identifier owes the grammar {@see BlockIdGrammar} holds: outside it the name is either lost - digits
	 * alone were silently renamed on the way in, so the agent went on addressing a node the template no longer
	 * carried under that name - or unreadable, because a substitution stops at the first character that cannot be
	 * part of a name. The grammar is a check the external contour ADDS, so it is asked of the profile (BRIEF § 4.4):
	 * the internal assistant authors numeric identifiers by the contract of its own tool and keeps them.
	 *
	 * An identifier the published version already carries is accepted as it stands, and only at the very same
	 * point: the pair id+type is the identity of ALG-02, so an old name cannot be copied onto a block of another
	 * type, while a block that merely came back unchanged keeps its round trip whatever the name looks like.
	 */
	private function validateId(mixed $id, string $path, array $blackListIds, mixed $type): Result
	{
		$idPath = "{$path}.id";

		if (!is_string($id) || $id === '')
		{
			return (new Result())->addError(GraphError::at(
				$idPath,
				"{$idPath} should be not empty string",
				GraphErrorCode::BlockIdInvalid,
			));
		}

		if (in_array($id, $blackListIds, true))
		{
			return (new Result())->addError(GraphError::at(
				$idPath,
				"{$idPath} should be unique value",
				GraphErrorCode::BlockIdDuplicated,
			));
		}

		if (
			$this->profile->enforcesBlockIdGrammar()
			&& !BlockIdGrammar::isAccepted($id)
			&& !$this->isCarriedByPublishedVersionAt($id, $type)
		)
		{
			return (new Result())->addError(GraphError::at(
				$idPath,
				"{$idPath} should be latin letters, digits and underscores, and not digits alone",
				GraphErrorCode::BlockIdInvalid,
			));
		}

		return new Result();
	}

	/**
	 * Whether the template the agent was handed carries this identifier on a block of this very type - the
	 * published version, or the graph template.get answered with ({@see ValidationContext::carriesBlockIdentity()}).
	 * Deliberately narrower than "the name exists somewhere": the exemption protects a block that came back as it
	 * was, and moving it between blocks would let the agent dress a new block in an old name and buy the grammar
	 * off with it (ALG-02, BRIEF § 4.3).
	 *
	 * The handed graph counts here for the same reason it counts in the option check: a block that lives only in
	 * the draft has no published twin, and a draft written before this grammar existed carries names outside it -
	 * the ids Marta composed with a hyphen, or the ones an external agent wrote while renaming was still silent.
	 * Refusing them would leave that draft unsavable by the very agent that was handed it. It is no licence to
	 * invent one: an id the agent composes itself matches nothing on either side.
	 */
	private function isCarriedByPublishedVersionAt(string $id, mixed $type): bool
	{
		return is_string($type) && $this->context?->carriesBlockIdentity($id, $type) === true;
	}

	private function validateTitle(mixed $title, string $path): Result
	{
		return $this->validateOptionalText($title, "{$path}.title", self::TITLE_MAX_LENGTH);
	}

	private function validateDescription(mixed $description, string $path): Result
	{
		return $this->validateOptionalText($description, "{$path}.description", self::DESCRIPTION_MAX_LENGTH);
	}

	private function validatePresetId(mixed $presetId, string $path): Result
	{
		return $this->validateOptionalText($presetId, "{$path}.presetId", self::PRESET_ID_MAX_LENGTH);
	}

	private function validateOptionalText(mixed $value, string $path, int $maxLength): Result
	{
		if ($value === null)
		{
			return new Result();
		}

		if (!is_string($value))
		{
			return (new Result())->addError(GraphError::at(
				$path,
				"{$path} should be string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		if (mb_strlen($value) > $maxLength)
		{
			return (new Result())->addError(
				GraphError::at(
					$path,
					"{$path} should be at most {$maxLength} characters",
					GraphErrorCode::GraphElementInvalid,
				),
			);
		}

		return new Result();
	}

	private function validateType(
		mixed $type,
		string $path,
		DocumentDescription $documentType,
		?string $presetId = null,
	): Result|BlockSettingsResult
	{
		$typePath = "{$path}.type";

		if (!is_string($type) || $type === '')
		{
			return (new Result())->addError(GraphError::at(
				$typePath,
				"{$typePath} should be not empty string",
				GraphErrorCode::GraphElementInvalid,
			));
		}

		$blockDetail = $this->blockDescriptionCache->get($type, $documentType, $presetId);

		if ($blockDetail === null)
		{
			return (new Result())->addError(
				GraphError::at($typePath, "{$typePath} is incorrect type", GraphErrorCode::BlockTypeUnknown),
			);
		}

		return new BlockSettingsResult($blockDetail);
	}

	private function carriesPublishedWriterUnchanged(array $block): bool
	{
		$type = $block['type'] ?? null;
		$id = $block['id'] ?? null;
		if (!is_string($type) || strcasecmp($type, 'writedatastorageactivity') !== 0 || !is_string($id))
		{
			return false;
		}

		$baseline = $this->context?->baseline->match($id, $type);
		if (
			$baseline === null
			|| ($block['title'] ?? null) !== $baseline->title
			|| ($block['description'] ?? null) !== $baseline->description
			|| ($block['presetId'] ?? '') !== ($baseline->presetId ?? '')
			|| !is_array($block['settings'] ?? null)
		)
		{
			return false;
		}

		$values = [];
		foreach ($block['settings'] as $setting)
		{
			$name = is_array($setting) ? ($setting['name'] ?? null) : null;
			if (!is_string($name) || $name === '' || !array_key_exists('value', $setting))
			{
				return false;
			}
			$name = mb_strtolower($name);
			if (array_key_exists($name, $values))
			{
				return false;
			}
			$values[$name] = $setting['value'];
		}

		// Compare every persisted setting, including names omitted or added in the submitted node.
		$names = array_keys($values);
		foreach ($baseline->settings as $setting)
		{
			$names[] = $setting->name;
		}

		return $this->context->baseline->requirementExemptAllowed($id, $type, $values, $names);
	}

	/**
	 * The block described in the context of the storage it addresses, so a dynamic value map is judged against
	 * the columns of that storage (ALG-01 `resolveKeys` -> `checkMapKeys`). The same detail back for every block
	 * that is not a storage consumer, and null when the description could not be built for the context at all.
	 *
	 * The code is read off the settings as SUBMITTED rather than off the validated collection: the description
	 * the settings are judged against has to be in hand before they are judged, and what the resolver may do with
	 * a code is settled by ownership rather than by the shape of the value ({@see StorageColumnSchemaResolver}).
	 */
	private function describedForItsStorage(
		BlockTypeDetail $blockDetail,
		string $type,
		?string $presetId,
		DocumentDescription $documentType,
		mixed $settings,
	): ?BlockTypeDetail
	{
		if ($this->storageColumns === null || !StorageNodeClassifier::isConsumer($type))
		{
			return $blockDetail;
		}

		$storageCode = self::submittedSettingValue($settings, 'StorageCode');

		return is_string($storageCode) && $storageCode !== ''
			? $this->storageColumns->describedForStorage($blockDetail, $type, $presetId, $documentType, $storageCode)
			: $blockDetail
		;
	}

	/**
	 * The value a submitted settings list carries under a name, matched without regard to case and answering with
	 * the LAST occurrence - the one the draft converter would persist, exactly as
	 * {@see StorageNodeClassifier::extractStorageCode()} reads it off the validated collection.
	 */
	private static function submittedSettingValue(mixed $settings, string $name): mixed
	{
		if (!is_array($settings))
		{
			return null;
		}

		$value = null;
		foreach ($settings as $setting)
		{
			$settingName = is_array($setting) ? ($setting['name'] ?? null) : null;
			if (is_string($settingName) && strcasecmp($settingName, $name) === 0)
			{
				$value = $setting['value'] ?? null;
			}
		}

		return $value;
	}

	/**
	 * Authorises a storage consumer node against the published template baseline via the bizproc
	 * {@see StorageReferenceGuard} (ALG-02). The guard stays free of agent types, so the node fingerprint is
	 * computed here, where the graph shape is known, and operationKind is derived per node - the guard refuses a
	 * result data-view only when editing.
	 *
	 * The owned codes come from the enriched context the first pass of {@see AgentBlocksValidator} assembles
	 * (ALG-11), so a consumer of storage this very graph creates is authorised by origin instead of by shape.
	 */
	private function authorizeStorageReference(string $type, string $path): Result
	{
		$settings = $this->settingsValidator->getValidSettings() ?? new AgentSettingCollection();
		$operationKind = StorageNodeClassifier::resolveOperationKind($type, $settings);
		if ($operationKind === null)
		{
			return new Result();
		}

		$verdict = $this->storageReferenceGuard->authorizeReference(
			new StorageReference(
				storageCode: StorageNodeClassifier::extractStorageCode($settings),
				storageId: StorageNodeClassifier::extractStorageId($settings),
				fingerprint: StorageNodeFingerprint::of($type, $settings),
			),
			$operationKind,
			$this->getStorageAuthorizationContext(),
		);

		if (!$verdict->allowed)
		{
			return (new Result())->addError(
				GraphError::at(
					"{$path}.settings",
					"{$path}.settings " . ($verdict->reason ?? 'storage reference is not allowed'),
					GraphErrorCode::StorageReferenceDenied,
				),
			);
		}

		return new Result();
	}

	/**
	 * Assembled once per validator, like the guard it is passed to: every field it reads is readonly and settled at
	 * construction, so the same context answers for every node of the run and the verdict cannot depend on which
	 * node asked first. Its constructor indexes both code sets - a regexp per code - which a per-node instance
	 * would repeat for each of the 256 blocks an entry may carry.
	 */
	private function getStorageAuthorizationContext(): StorageAuthorizationContext
	{
		return $this->storageAuthorizationContext ??= new StorageAuthorizationContext(
			templateId: $this->context?->templateId ?? 0,
			baselineStaticNodeFingerprints: $this->context?->baselineStaticNodeFingerprints ?? [],
			baselineStorageCodes: $this->context?->baselineStorageCodes ?? [],
			ownedStorageCodes: $this->context?->ownedStorageCodes ?? [],
		);
	}

	/**
	 * Authorises the restricted passthrough settings of a node against the graph the SERVER wrote (ALG-04). A
	 * passthrough setting has no typed passport, so its value is otherwise taken verbatim; where that value is an
	 * executable payload - the ifelse branch condition whose class requires admin rights to author - the agent may
	 * only re-send what it was given: the published version, or the graph `template.get` handed it
	 * ({@see ValidationContext::carriesRestrictedSettingValue()}).
	 *
	 * The two halves of the rule part on whether there is a payload at all. A written value must match byte for
	 * byte, so a template with neither a published version nor a draft refuses every one of them - it has nothing to
	 * carry over. An empty value ({@see RestrictedSettingRegistry::carriesNoPayload()}) is authorised on its own,
	 * because what is vouched for is a payload and here there is none: the dialog writes such a setting on every
	 * save whether or not a human filled it, so holding it to the server's graph would cost a node that lives only
	 * in the draft the whole graph over a setting that carries nothing.
	 *
	 * The handed graph is no way around this barrier, because the barrier itself is what keeps the agent out of it:
	 * a value it authors is refused here on the write path as well, so a restricted value stands in the draft as the
	 * published one or as one a person wrote in the editor. What it stands ON is judged by the location half on the
	 * graph pass ({@see AgentBlocksValidator::validateRestrictedSettingReferences()}), so an authorised value cannot
	 * be copied onto another node either.
	 *
	 * The settings are handed in rather than read off the settings validator, because the verbatim branch collects
	 * its own and must reach this barrier through the very same code the ordinary path does.
	 */
	private function authorizeRestrictedSettings(
		string $type,
		DocumentDescription $documentType,
		AgentSettingCollection $settings,
		string $path,
	): Result
	{
		$restrictedNames = $this->restrictedSettings->namesFor($type, $documentType);
		if ($restrictedNames === [])
		{
			return new Result();
		}

		$result = new Result();
		foreach ($settings as $setting)
		{
			if (!in_array(mb_strtolower($setting->name), $restrictedNames, true))
			{
				continue;
			}

			if (RestrictedSettingRegistry::carriesNoPayload($setting->value))
			{
				continue;
			}

			// Fail-closed like every other reader of the context: no context at all is no graph the server wrote,
			// and the payload is judged as one the agent authored.
			if (!$this->context?->carriesRestrictedSettingValue($setting->name, $setting->value))
			{
				$result->addError(GraphError::at(
					"{$path}.settings.{$setting->name}",
					"{$path}.settings.{$setting->name} must match the published template baseline"
					. ' (this setting carries an executable payload and can only be passed through unchanged)',
					GraphErrorCode::ImmutableNodePolicyViolation,
				));
			}
		}

		return $result;
	}

	/**
	 * Fail-closed guard: a block whose type validates must also have a resolvable, non-empty system name.
	 * That name becomes node.title in the draft converter (same source — ActivityDescription::getName()),
	 * so a nameless-but-otherwise-valid type must be rejected here instead of yielding an empty node title.
	 *
	 * Multi-preset activities carry their name on the preset, so the guard resolves with the supplied
	 * presetId: a preset block with a valid presetId passes, while the same type without a presetId (empty
	 * base name) is still rejected — the error hints that a presetId is required for such blocks.
	 *
	 * The one exemption is a nameless internal node ({@see NamelessInternalNodeMatcher}), whose system name is
	 * empty by design. It is granted by type, not by "any empty name", so a genuinely nameless (broken or
	 * obsolete) block still fails the guard.
	 */
	private function validateSystemName(mixed $type, mixed $presetId, string $path): Result
	{
		$normalizedPresetId = is_string($presetId) && $presetId !== '' ? $presetId : null;
		$systemName = is_string($type)
			? $this->metadataResolver->resolveSystemName($type, $normalizedPresetId)
			: '';
		if (trim($systemName) === '')
		{
			$typePath = "{$path}.type";

			// The internal assistant never reaches here (the node is hidden from it), so the profile check is
			// defence in depth.
			if ($this->profile->allowsFrameOverlay() && NamelessInternalNodeMatcher::matches($type))
			{
				return new Result();
			}

			$message = "{$typePath} block system name is not defined";
			if ($normalizedPresetId === null)
			{
				// The presetId hint only helps when none was supplied; with a bad presetId the
				// "not a valid preset" error already explains the failure, so the hint would mislead.
				$message .= ' (specify presetId for multi-preset blocks)';
			}

			return (new Result())->addError(GraphError::at($typePath, $message, GraphErrorCode::PresetIdInvalid));
		}

		return new Result();
	}

	public function getValidBlock(): ?AgentBlock
	{
		return $this->validBlock;
	}

	public function getValidBlockDetail(): ?BlockTypeDetail
	{
		return $this->validBlockDetail;
	}

	public function getId(): mixed
	{
		return $this->id;
	}

	/**
	 * Risks of the last run that did not refuse the block (DTO-02). Whether they reach the agent at all is
	 * decided where the report is assembled, not here ({@see SaveWorkflowValidator::getWarnings()}).
	 *
	 * @return list<Error>
	 */
	public function getWarnings(): array
	{
		return $this->warnings;
	}
}

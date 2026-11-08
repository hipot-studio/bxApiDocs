<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\BizprocDesigner\Internal\Entity\BlockType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeCollection;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnField;
use Bitrix\BizprocDesigner\Internal\Entity\ReturnFieldCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockContextRejectionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockDescriptionResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\BaselineProjection;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Catalog of workflow activities for the external agent REST API.
 * Uses Searcher + Activities directly to work with typed ActivityDescription objects
 * and apply document-type filters before returning results.
 */
final class AgentBlockCatalogService
{
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.block_catalog';

	private ?LoggerInterface $logger = null;
	private ?NodeTopologyResolver $nodeTopologyResolver = null;

	private ?BaselineProjection $publishedBaseline = null;
	private ?int $publishedBaselineTemplateId = null;

	/**
	 * @throws LoaderException
	 */
	public function getBlocksWithDescription(DocumentDescription $documentType): Result|BlockDescriptionResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_MODULE_NOT_INSTALLED')));
		}

		$documentBizprocType = $documentType->toBizprocComplexType();

		/** @var Activities<ActivityDescription> $activities */
		$activities = BizprocContainer::instance()
			->getActivitySearcherService()
			->searchByType(
				[
					ActivityType::NODE->value,
					ActivityType::TRIGGER->value,
				],
				$documentBizprocType,
			)
			->computeDescriptionFilter($documentBizprocType)
			->sort()
		;

		$blocks = new BlockTypeCollection();
		foreach ($activities as $type => $activityDescription)
		{
			if (!$this->isOffered($activityDescription))
			{
				continue;
			}

			$presets = $activityDescription->getPresets();
			if ($presets !== null && count($presets) > 0)
			{
				foreach ($presets as $preset)
				{
					$presetApplied = $activityDescription->applyPreset($preset);
					$description = $this->resolveDescription($presetApplied);
					if (!$description)
					{
						continue;
					}

					$settingsResult = $this->getActivityAiDescription(
						(string)$type,
						$documentBizprocType,
					);

					if (!$settingsResult instanceof ActivityAiDescriptionResult)
					{
						$this->logSkippedBlock((string)$type . '#' . $preset['ID'], $settingsResult);

						continue;
					}

					$blocks->add(
						new BlockType(
							type: (string)$type,
							description: $description,
							presetId: (string)$preset['ID'],
							hasSettings: $settingsResult->settings->count() > 0,
						),
					);
				}

				continue;
			}

			$description = $this->resolveDescription($activityDescription);
			if (!$type || !$description)
			{
				continue;
			}

			$settingsResult = $this->getActivityAiDescription(
				(string)$type,
				$documentBizprocType,
			);

			if (!$settingsResult instanceof ActivityAiDescriptionResult)
			{
				$this->logSkippedBlock((string)$type, $settingsResult);

				continue;
			}

			$blocks->add(
				new BlockType(
					type: $type,
					description: $description,
					hasSettings: $settingsResult->settings->count() > 0,
				),
			);
		}

		return new BlockDescriptionResult($blocks);
	}

	/**
	 * The full read of one block, described in the context the caller asked for it in.
	 *
	 * Answers for exactly the variants the listing publishes ({@see self::isOffered()}, AC-31), so a type with
	 * presets is read by naming one of them and never bare.
	 *
	 * @param mixed $contextSettings raw `{name, value}` pairs of the request, or null for the plain read. The
	 *        preset is applied first and these refine it, over the settings the description publishes as the
	 *        agent's to write ({@see AgentBlockContextResolver}). They never touch the document type: the
	 *        catalog is computed for the type of the bound template, and a context that could choose it would
	 *        widen the read past what the token authorizes.
	 *
	 * @param int|null $templateId the bound template the context is judged for. Only the document types it may
	 *        name are accepted in a setting that carries one ({@see TrustedDocumentTypeResolver}); without it
	 *        the published version of the template contributes none of its own, which narrows the set and never
	 *        widens it.
	 *
	 * @return Result|BlockSettingsResult a {@see BlockContextRejectionResult} when the refusal is about the
	 *         context rather than about the block, so the two are answered with different statuses
	 *
	 * @throws LoaderException
	 */
	public function getBlockSettings(
		DocumentDescription $documentType,
		string $blockType,
		string $presetId = '',
		mixed $contextSettings = null,
		?int $templateId = null,
	): Result|BlockSettingsResult
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_MODULE_NOT_INSTALLED')),
			);
		}

		$documentBizprocType = $documentType->toBizprocComplexType();
		$searcher = BizprocContainer::instance()->getActivitySearcherService();

		$activityDescription = $searcher->searchByCode($blockType);
		if ($activityDescription === null)
		{
			return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')));
		}

		// computeDescriptionFilter mutates $activityDescription's excluded flag in-place
		(new Activities([$blockType => $activityDescription]))->computeDescriptionFilter($documentBizprocType);
		if (!self::declaresCatalogType($activityDescription) || !$this->isOffered($activityDescription))
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')),
			);
		}

		$presets = $activityDescription->getPresets();

		$defaultValues = null;
		if ($presetId === '')
		{
			// A type with variants is published by the listing as one record per variant and never as the
			// bare type, so reading it bare would hand the agent a block the catalog never offered - and a
			// schema built without the values the variant pins (BRIEF § 7.2).
			if ($presets !== null && count($presets) > 0)
			{
				return (new Result())->addError(new Error(
					Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')),
				);
			}
		}
		else
		{
			$preset = $activityDescription->getPresetById($presetId);
			if ($preset === null)
			{
				return (new Result())->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')));
			}

			$activityDescription = $activityDescription->applyPreset($preset);

			// One reading of what a preset pins, shared with the description context: two copies of the
			// expression filter would drift, and then the catalog would publish a value the description
			// was never built with.
			$defaultValues = (new AgentBlockMetadataResolver())->presetProperties($blockType, $presetId);
		}

		// Read off the variant the caller asked for, exactly as the listing reads it: a type the listing
		// skips for having no text of its own is not readable here either.
		$description = $this->resolveDescription($activityDescription);
		if ($description === '')
		{
			return (new Result())->addError(new Error(
				Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_BLOCK_NOT_FOUND')),
			);
		}

		// The preset's own values are what several activities resolve their option lists from: a CRM trigger
		// reads the field list of the entity its `Document` names. Describing the block without them answers
		// with an empty SELECT on a required setting - the agent is then told to pick a code and shown none,
		// and whatever it invents passes validation and breaks when the process runs.
		$result = $this->getActivityAiDescription(
			$blockType,
			$documentBizprocType,
			$defaultValues === null ? [] : ['Properties' => $defaultValues],
		);
		if (!$result instanceof ActivityAiDescriptionResult)
		{
			$this->logSkippedBlock($blockType, $result);

			return (new Result())
				->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_BLOCK_CATALOG_ERR_SETTINGS_NOT_DESCRIBED')))
				->addErrors($result->getErrors())
			;
		}

		if ($contextSettings !== null)
		{
			$contextual = $this->describeInContext(
				$blockType,
				$documentType,
				$defaultValues,
				$contextSettings,
				$result,
				$templateId,
			);
			if (!$contextual instanceof ActivityAiDescriptionResult)
			{
				return $contextual;
			}

			$result = $contextual;
		}

		$complexDetail = (new ComplexBlockDetailFactory())->build(
			$blockType,
			$activityDescription->getNodeType(),
			$documentBizprocType,
		);

		return new BlockSettingsResult(new BlockTypeDetail(
			block: new BlockType(
				type: $blockType,
				description: $description,
				presetId: $presetId !== '' ? $presetId : null,
			),
			settings: $result->settings,
			returnFields: $this->buildReturnFields($activityDescription->getReturn()),
			describedTypes: (new BlockDescribedTypesResolver())->resolve($result->settings, $complexDetail),
			skippedSettings: $result->skippedSettings,
			carriedSettings: $result->carriedSettings,
			settingConstraints: $result->settingConstraints,
			defaultValues: $defaultValues,
			nodeSettings: $this->getNodeTopologyResolver()->resolveNodeSettings($activityDescription),
			complexDetail: $complexDetail,
		));
	}

	/**
	 * The block described again, this time in the context the caller sent, over the values the preset pins.
	 *
	 * Two describes rather than one: the pairs are judged against the schema the block was already described
	 * with, so a name is answered by the schema the agent was given instead of by one the same request had
	 * just changed. The description of an activity is cached by its context
	 * ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription()}), so the second read of the same
	 * pair costs nothing and the two contexts never share an answer.
	 *
	 * @param array<string, mixed>|null $presetProperties what the preset pins, applied first
	 *
	 * @return ActivityAiDescriptionResult|BlockContextRejectionResult
	 */
	private function describeInContext(
		string $blockType,
		DocumentDescription $documentType,
		?array $presetProperties,
		mixed $contextSettings,
		ActivityAiDescriptionResult $baseDescription,
		?int $templateId,
	): ActivityAiDescriptionResult|BlockContextRejectionResult
	{
		// The context may not choose the document type of the read, and a setting that carries one may not
		// choose it either: the two would be the same widening by another route. A storage it names is held to
		// the ownership of the bound template for the same reason, and by the same rule the graph path uses.
		$trustedTypes = new TrustedDocumentTypeResolver();
		// The address the context named, kept as the caller wrote it and as the gate answered for it: a code the
		// gate refused leaves the second null, and the declaration below is then never read for it.
		$storageCodeAsSent = null;
		$authorizedStorageCode = null;
		$storageAuthorization = null;
		$contextResult = (new AgentBlockContextResolver())->resolve(
			$contextSettings,
			$baseDescription,
			static fn(DocumentDescription $candidate) => $trustedTypes->isAllowed($documentType, $templateId, $candidate),
			// Built on first use: a context naming no storage never reads the published version for one.
			function (string $storageCode) use (
				$templateId,
				&$storageAuthorization,
				&$storageCodeAsSent,
				&$authorizedStorageCode,
			): ?string {
				$storageAuthorization ??= $this->publishedStorageAuthorization($templateId);
				$storageCodeAsSent = $storageCode;

				return $authorizedStorageCode = StorageColumnSchemaResolver::authorizedLookupCode(
					$storageAuthorization,
					$storageCode,
					$templateId ?? 0,
				);
			},
		);
		if (!$contextResult->isSuccess())
		{
			return (new BlockContextRejectionResult())->addErrors($contextResult->getErrors());
		}

		$contextProperties = $contextResult->getData()['properties'];
		if ($contextProperties === [])
		{
			return $baseDescription;
		}

		$result = $this->getActivityAiDescription(
			$blockType,
			$documentType->toBizprocComplexType(),
			['Properties' => array_replace($presetProperties ?? [], $contextProperties)],
		);
		if ($result instanceof ActivityAiDescriptionResult)
		{
			// The declaration answers for the address the block was DESCRIBED by, which is the one the gateway
			// let through whole. A context naming a code this template owns beside an id it does not is rewritten
			// to an address that resolves to nothing - the runtime would prefer the id (ALG-02 rule 1) - and
			// answering it by the code would publish the columns of a storage the node would never write into.
			$describedByCode =
				$authorizedStorageCode !== null
				&& ($contextProperties[AgentBlockContextResolver::STORAGE_CODE_SETTING] ?? null) === $authorizedStorageCode
			;

			return $this->withPublishedColumns(
				$result,
				$documentType,
				$templateId,
				$describedByCode ? $storageCodeAsSent : null,
			);
		}

		$this->logSkippedBlock($blockType, $result);

		// Answered as a failure, never as the description built without the context: a map whose keys the
		// server failed to resolve would then be published as one that admits any key (BRIEF § 1.4). The reason
		// stays in the log - it is about the portal, not about anything the caller can correct.
		return (new BlockContextRejectionResult())->addError(GraphError::at(
			'contextSettings',
			'contextSettings cannot be applied: the server could not describe this block for it',
			GraphErrorCode::ContextResolutionFailed,
		));
	}

	/**
	 * The description with the keys of a value map the portal could not name answered by the columns the
	 * PUBLISHED create node declares for the code (BRIEF § 3.4, source 3).
	 *
	 * Without this the two paths answered one question differently: the graph check judges such a map against the
	 * declaration, so `template.validate` refused a key while `catalog.block.get` would not say which keys it
	 * would accept - the agent could not learn from the catalog what its own graph is checked by. The declaration
	 * is read through the very call the resolver reads it by, so the two cannot drift apart again.
	 *
	 * The storage that exists keeps its precedence: a map that already carries children was answered by the
	 * portal, and only the maps left unanswered reach the declaration.
	 *
	 * @param string|null $storageCode the code the context named, as the caller wrote it, and only when the gate
	 *        authorised it. Null keeps the answer exactly as it was: an address the template may not read is
	 *        answered by no declaration either, so the unauthorised addresses stay indistinguishable from one
	 *        another and from a storage that does not exist
	 */
	private function withPublishedColumns(
		ActivityAiDescriptionResult $described,
		DocumentDescription $documentType,
		?int $templateId,
		?string $storageCode,
	): ActivityAiDescriptionResult
	{
		if ($storageCode === null)
		{
			return $described;
		}

		$declared = StorageColumnSchemaResolver::publishedColumnsOf(
			$this->publishedBaseline($templateId),
			$storageCode,
			$templateId ?? 0,
			$documentType,
		);
		if ($declared === null)
		{
			return $described;
		}

		return new ActivityAiDescriptionResult(
			code: $described->code,
			settings: StorageColumnSchemaResolver::withDeclaredKeys($described->settings, $declared),
			skippedSettings: $described->skippedSettings,
			carriedSettings: $described->carriedSettings,
			settingConstraints: $described->settingConstraints,
		);
	}

	/**
	 * The storage the bound template may be described in the context of, read off its PUBLISHED version alone.
	 *
	 * The published version is the only graph consulted here, exactly as it is on the graph path: the draft and
	 * the request itself are what this gate exists to keep out, and a template that could name its own storage
	 * would be authorizing itself. The create nodes of the published version own their codes, and the codes it
	 * merely declares are added on top, whose storage the setup wizard or a person may have made.
	 *
	 * Both sides of the comparison are expanded to the code the runtime addresses storage by: the canonical form
	 * of a code a template owns carries the per-template substitution, so a set indexed as written would
	 * authorise neither the expanded spelling the storage really has nor the token spelling the agent was handed.
	 *
	 * Without a bound template nothing is owned or declared, which refuses every storage the context could name.
	 */
	private function publishedStorageAuthorization(?int $templateId): StorageAuthorizationContext
	{
		$baseline = $this->publishedBaseline($templateId);

		return StorageColumnSchemaResolver::expandedAuthorizationOf(
			$templateId ?? 0,
			$baseline->storageCodes(),
			$baseline->creatorStorageCodes(),
		);
	}

	/**
	 * The published version of the bound template, projected once per read: the gate above and the declared
	 * columns are two questions about one graph, and converting it twice would let them answer for two.
	 */
	private function publishedBaseline(?int $templateId): BaselineProjection
	{
		if ($this->publishedBaseline !== null && $this->publishedBaselineTemplateId === $templateId)
		{
			return $this->publishedBaseline;
		}

		$publishedTemplate = $templateId !== null && $templateId > 0
			? Container::getAgentWorkflowResolverService()->getPublishedAgentTemplate($templateId)
			: null
		;

		$this->publishedBaselineTemplateId = $templateId;

		return $this->publishedBaseline = new BaselineProjection($publishedTemplate?->blocks ?? []);
	}

	/**
	 * The one predicate both actions of the catalog judge a type by, so that the full read never answers for
	 * a type the listing leaves out (AC-31): an agent offered a block only the detail knows would build a
	 * graph the server then refuses.
	 *
	 * Excluded and deprecated types are the environment's own answer about the block, and an incompatible
	 * topology (a SERVICE or TOOL node) is one the draft converter cannot build. FRAME (the frame overlay)
	 * passes all three, and its empty property dialog yields an empty settings schema without being skipped.
	 *
	 * The two remaining reasons the listing drops a record - a variant with no text of its own and a type
	 * whose schema does not build - depend on the variant being read and are judged where it is known.
	 */
	/**
	 * Whether the activity declares one of the two types this catalog is built of. The listing selects by exactly
	 * these ({@see self::getBlocksWithDescription()} -> `searchByType`), while reading a single block resolves it
	 * by code, which asks about no type at all - so without this the two actions disagree on a type declaring
	 * neither, and `get` answers with a schema for a block the listing never published (BRIEF § 7.2, AC-31).
	 *
	 * The manual start trigger is the everyday case: it declares no type, so it belongs to no catalog. Its
	 * round-trip does not depend on this answer - a published node of an undescribed type travels back verbatim,
	 * without a schema ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlockValidator}).
	 */
	private static function declaresCatalogType(ActivityDescription $activityDescription): bool
	{
		$declared = array_map(
			static fn($type) => mb_strtolower(trim((string)$type)),
			$activityDescription->getType(),
		);

		return array_intersect([ActivityType::NODE->value, ActivityType::TRIGGER->value], $declared) !== [];
	}

	private function isOffered(ActivityDescription $activityDescription): bool
	{
		return !$activityDescription->getExcluded()
			&& !$activityDescription->getDeprecated()
			&& $this->isNodeCompatible($activityDescription)
		;
	}

	/**
	 * Returns false for node topologies that the agent draft converter cannot build.
	 *
	 * Delegates to the shared ActivityVisibilityFilter predicate so that both the REST
	 * catalog and the Marta catalog apply identical placement guards.
	 *
	 * @see ActivityVisibilityFilter::isNodeCompatible()
	 * @see ActivityVisibilityFilter::LEGACY_COMPOSITE_CLASS_BLACKLIST
	 */
	private function isNodeCompatible(ActivityDescription $d): bool
	{
		return ActivityVisibilityFilter::isNodeCompatible($d);
	}

	private function resolveDescription(ActivityDescription $activity): string
	{
		foreach ([
			$activity->get('AI_DESCRIPTION'),
			$activity->getDescription(),
			$activity->getName(),
		] as $value)
		{
			if (is_string($value) && $value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	private function getNodeTopologyResolver(): NodeTopologyResolver
	{
		return $this->nodeTopologyResolver ??= new NodeTopologyResolver();
	}

	private function buildReturnFields(array $activityReturn): ReturnFieldCollection
	{
		$returnFields = new ReturnFieldCollection();

		foreach ($activityReturn as $name => $field)
		{
			$description = (string)($field['NAME'] ?? '');
			if ($description === '')
			{
				continue;
			}

			$type = (string)($field['TYPE'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$returnFields->add(
				new ReturnField(
					name: $name,
					description: $description,
					type: $type,
				),
			);
		}

		return $returnFields;
	}

	private function getActivityAiDescription(
		string $blockType,
		array $documentBizprocType,
		array $context = []
	): Result|ActivityAiDescriptionResult
	{
		return \CBPRuntime::getRuntime()
			->getAiDescriptionService()
			->getActivityDescription($blockType, $documentBizprocType, $context)
		;
	}

	private function logSkippedBlock(string $blockType, Result $result): void
	{
		$reasons = [];
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			$reasons[] = ($code !== '' && $code !== '0' ? $code . ': ' : '') . $error->getMessage();
		}

		$this->getLogger()->info(
			'AI block catalog: activity is excluded from the catalog',
			[
				'activity' => $blockType,
				'reasons' => $reasons,
			],
		);
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingKeyMode;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache\BlockDescriptionCache;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\BaselineProjection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext;

/**
 * The block of a storage consumer, described in the context of the storage it addresses, so the keys of its
 * dynamic value map are judged on the graph path as they are on the catalog path (BRIEF § 3.2, § 3.4, ALG-01
 * `resolveKeys` -> `checkMapKeys`).
 *
 * The defect this exists for: `template.validate` and `template.draft.add` read the block description without a
 * context, so a `FieldValue` map arrived with `keyMode` unset, the dynamic branch of
 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingValueValidator} never ran,
 * and a graph writing into a column no storage has was answered `valid: true` - while `catalog.block.get` for the
 * very same code listed the columns in the same minute.
 *
 * The columns come from the three sources of BRIEF § 3.4, in that order and no other:
 *   1. a create node of THIS graph the admission policy let through, by its declared columns. It comes first
 *      because a graph may declare a column the storage does not have yet - the create node adds it while the
 *      process runs - and the portal would then refuse a value the graph itself makes valid;
 *   2. the storage that already exists, read through the very path the catalog reads it by: the block described
 *      in the context of its own code ({@see AgentBlockContextResolver} admits the value, the activity resolves
 *      the columns). No second reading of the portal is opened here. It outranks the published declaration
 *      below, because a column a person added to the storage through the admin surface is a column the runtime
 *      writes into today, while the published node states what the storage was made from;
 *   3. the create node of the published version, by its declared columns - the template owns the code, and its
 *      published declaration is what the storage will be once the process runs.
 *
 * The code is only ever looked up when the template OWNS or DECLARES it ({@see StorageAuthorizationContext}): a
 * submitted consumer does not authorise its own code (BRIEF § 3.4), and answering a foreign code with its column
 * list would make the check report of the contour a way to read the storage of another template. Ownership is
 * read through the one call the catalog path reads it by ({@see self::authorizedLookupCode()}), with both sides
 * of the comparison expanded to the code the runtime addresses storage by, so the two paths cannot come to
 * differ on which storage a code names.
 *
 * When none of the three can name the columns - the code is the template's, no create node declares it and no
 * such storage exists yet - the block keeps the description it had before, with the keys unjudged. That is the
 * state the activity itself documents for a description built without a context, and it is not a context failure
 * turned into a free map (AC-37): the failure of a provider is the description the server could not build at all,
 * and that one is answered with `ContextResolutionFailed` by the caller. The catalog answers the same state with
 * a dynamic map whose keys are uncomputed, which refuses every key; the two differ on purpose, because refusing
 * here would cost its round trip (AC-35) a template published on a portal where the process has never run.
 *
 * One resolution per (document type, normalised storage code, block type, preset) and no more: a graph writing
 * into one storage from a dozen nodes describes the block once, and the reading of a code cannot differ between
 * two nodes of it.
 */
final class StorageColumnSchemaResolver
{
	/** The setting a storage consumer is described in the context of; the only address the agent may use. */
	private const STORAGE_CODE_SETTING = AgentBlockContextResolver::STORAGE_CODE_SETTING;

	private readonly BlockDescriptionCache $descriptions;
	private readonly ?ValidationContext $context;

	/**
	 * Columns the create nodes of the graph under validation declare, keyed by the NORMALISED storage code -
	 * the way ownership is indexed everywhere else ({@see StorageReferenceGuard::normalizeCode()}). Only the
	 * nodes the admission policy let through are here: a refused create node owns no code and declares nothing.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $graphColumns;

	private ?StorageAuthorizationContext $authorization = null;

	/** @var array<string, ?BlockTypeDetail> memo of the described block, keyed by {@see self::memoKey()} */
	private array $describedByKey = [];

	/** @var array<string, ?SettingCollection> memo of source 1, keyed by {@see self::memoKey()} */
	private array $graphColumnsByCode = [];

	/** @var array<string, ?SettingCollection> memo of source 3, keyed by {@see self::memoKey()} */
	private array $publishedColumnsByCode = [];

	/**
	 * @param array<string, mixed> $graphColumns normalised storage code => the `SelectedFields` value of the
	 *        create node that declares it, as submitted
	 */
	public function __construct(
		BlockDescriptionCache $descriptions,
		?ValidationContext $context,
		array $graphColumns = [],
	)
	{
		$this->descriptions = $descriptions;
		$this->context = $context;
		$this->graphColumns = $graphColumns;
	}

	/**
	 * The block described with the keys of its dynamic maps resolved, the same detail back when no source can
	 * name them, or null when the server could not describe the block in this context at all - the failure of
	 * the provider, which the caller answers with `ContextResolutionFailed` (BRIEF § 11).
	 */
	public function describedForStorage(
		BlockTypeDetail $plain,
		string $blockType,
		?string $presetId,
		DocumentDescription $documentType,
		string $storageCode,
	): ?BlockTypeDetail
	{
		// One call answers both halves of the question, exactly as it does on the catalog path: whether the
		// bound template may address this storage, and which spelling of the code finds it. A lookup made with
		// the unexpanded text would find nothing for every per-template code and read that as "no such storage".
		$lookupCode = self::authorizedLookupCode($this->authorization(), $storageCode, $this->templateId());
		if ($lookupCode === null)
		{
			return $plain;
		}

		$key = self::memoKey($documentType, $storageCode, $blockType, $presetId);
		if (array_key_exists($key, $this->describedByKey))
		{
			return $this->describedByKey[$key];
		}

		return $this->describedByKey[$key] =
			$this->describe($plain, $blockType, $presetId, $documentType, $storageCode, $lookupCode)
		;
	}

	/**
	 * What a memo of this resolution is answered by.
	 *
	 * The document type leads it because every answer is read in the context of one: the block is described
	 * for it, and a declared column is converted through its own field types
	 * ({@see self::columnsOfDeclaration()}). A run judges one type today, but a key blind to it would answer
	 * the second with the columns of the first the moment one stops being true.
	 */
	private static function memoKey(
		DocumentDescription $documentType,
		string $storageCode,
		?string $blockType = null,
		?string $presetId = null,
	): string
	{
		return implode('|', [
			$documentType->toCanonicalString(),
			StorageReferenceGuard::normalizeCode($storageCode),
			$blockType === null ? '' : mb_strtolower($blockType),
			$presetId ?? '',
		]);
	}

	/**
	 * @param string $storageCode the code as the node writes it, which is how a declaration of it is indexed
	 * @param string $lookupCode the same code as the runtime addresses storage by, already authorised
	 */
	private function describe(
		BlockTypeDetail $plain,
		string $blockType,
		?string $presetId,
		DocumentDescription $documentType,
		string $storageCode,
		string $lookupCode,
	): ?BlockTypeDetail
	{
		// A declaration is looked up by the spelling it was DECLARED in, while the storage itself is looked up by
		// the expanded one. The two cannot be one here: both maps arrive keyed by the normalised code, and
		// normalising folds case - which is exactly what makes the per-template token unreadable, since the
		// runtime and the barrier match '{=Workflow:...}' case-sensitively.
		$declaredAs = StorageReferenceGuard::normalizeCode($storageCode);

		// Create nodes of this graph that disagree about the code leave it without a contract at all, and the
		// storage that happens to exist under it does not become one: the order check refuses such a graph
		// (`StorageCreatorConflict`), and a key refusal on top would be a second, dependent cause (BRIEF § 1.4).
		if (array_key_exists($declaredAs, $this->graphColumns) && $this->graphColumns[$declaredAs] === null)
		{
			return $plain;
		}

		$contextResult = (new AgentBlockContextResolver())->resolve(
			[['name' => self::STORAGE_CODE_SETTING, 'value' => $lookupCode]],
			self::asDescriptionResult($blockType, $plain),
			// The code reached this method through the ownership gate of describedForStorage() and is already
			// expanded, so the gateway is told to describe the block by it instead of being made to judge it a
			// second time - and it is told by this call alone, never by a default of its own: without the
			// closure the gateway rewrites the address to one that resolves to nothing, and the columns of a
			// storage this template does own would then go unread.
			authorizeStorageCode: static fn(string $code): string => $code,
		);
		$properties = $contextResult->isSuccess() ? $contextResult->getData()['properties'] : [];
		if ($properties === [])
		{
			// The block is not described by a storage code at all - it has no such setting, or the setting is
			// not one a value may be sent for. Nothing to resolve keys from, and nothing failed.
			return $plain;
		}

		$contextual = $this->descriptions->get($blockType, $documentType, $presetId, $properties);
		if ($contextual === null)
		{
			return null;
		}

		return self::withResolvedKeys(
			$contextual,
			$plain,
			$this->graphColumnsFor($declaredAs, $documentType),
			$this->publishedColumnsFor($storageCode, $documentType),
		);
	}

	/**
	 * The contextual description with every dynamic map whose keys stayed unresolved answered by the three
	 * sources of BRIEF § 3.4, in their order, and the plain setting put back where none of them can answer.
	 *
	 * The create node of this graph comes first: it may add a column the storage does not carry yet, and the
	 * portal reading would then refuse the very value the graph makes valid. The storage that exists comes
	 * before the published declaration, because it is what the runtime writes into today - a column a person
	 * added through the admin surface belongs to it, and judging by the published node would refuse a value
	 * written into a column the storage really has.
	 */
	private static function withResolvedKeys(
		BlockTypeDetail $contextual,
		BlockTypeDetail $plain,
		?SettingCollection $graphDeclared,
		?SettingCollection $publishedDeclared,
	): BlockTypeDetail
	{
		$settings = new SettingCollection();
		foreach ($contextual->settings as $setting)
		{
			/** @var Setting $setting */
			if ($setting->keyMode !== SettingKeyMode::Dynamic)
			{
				$settings->add($setting);

				continue;
			}

			$children = $graphDeclared ?? $setting->children ?? $publishedDeclared;
			if ($children === null)
			{
				// Nobody can name the keys of this map. The setting the block was described with before the
				// context is put back, so the keys stay unjudged instead of being refused wholesale.
				$settings->add($plain->settings->findFirstByName($setting->name) ?? $setting);

				continue;
			}

			$settings->add($children === $setting->children ? $setting : self::withChildren($setting, $children));
		}

		return new BlockTypeDetail(
			block: $contextual->block,
			settings: $settings,
			returnFields: $contextual->returnFields,
			describedTypes: $contextual->describedTypes,
			skippedSettings: $contextual->skippedSettings,
			carriedSettings: $contextual->carriedSettings,
			settingConstraints: $contextual->settingConstraints,
			defaultValues: $contextual->defaultValues,
			nodeSettings: $contextual->nodeSettings,
			complexDetail: $contextual->complexDetail,
		);
	}

	private static function withChildren(Setting $setting, SettingCollection $children): Setting
	{
		return new Setting(
			name: $setting->name,
			description: $setting->description,
			type: $setting->type,
			required: $setting->required,
			multiple: $setting->multiple,
			options: $setting->options,
			children: $children,
			defaultValue: $setting->defaultValue,
			allowSelection: $setting->allowSelection,
			category: $setting->category,
			keyMode: $setting->keyMode,
			uniqueChildKey: $setting->uniqueChildKey,
			minimum: $setting->minimum,
			maximum: $setting->maximum,
		);
	}

	/**
	 * The columns a create node of THIS graph declares for the code, as a child schema (BRIEF § 3.4, source 1).
	 * Null when no admitted create node of the graph declares it.
	 */
	private function graphColumnsFor(string $code, DocumentDescription $documentType): ?SettingCollection
	{
		$key = self::memoKey($documentType, $code);
		if (array_key_exists($key, $this->graphColumnsByCode))
		{
			return $this->graphColumnsByCode[$key];
		}

		return $this->graphColumnsByCode[$key] = self::columnsOfDeclaration(
			$this->graphColumns[$code] ?? null,
			$documentType,
		);
	}

	/**
	 * The columns the create node of the PUBLISHED version declares for the code (BRIEF § 3.4, source 3). Null
	 * when it declares none - including the published version whose own creators disagree, which the projection
	 * leaves out of the map rather than resolving.
	 */
	private function publishedColumnsFor(string $storageCode, DocumentDescription $documentType): ?SettingCollection
	{
		$key = self::memoKey($documentType, $storageCode);
		if (array_key_exists($key, $this->publishedColumnsByCode))
		{
			return $this->publishedColumnsByCode[$key];
		}

		$baseline = $this->context?->baseline;

		return $this->publishedColumnsByCode[$key] = $baseline === null
			? null
			: self::publishedColumnsOf($baseline, $storageCode, $this->templateId(), $documentType)
		;
	}

	/**
	 * The columns the create node of a published version declares for a code, as the child schema of a value map
	 * - the one reading of source 3, shared with the catalog read path
	 * ({@see AgentBlockCatalogService::describeInContext()}). Two readings of it would put the catalog and the
	 * graph check back where this subsystem found them: one naming columns the other refuses.
	 *
	 * The code asked about and the code declared are matched by what they EXPAND to, through the one reading of
	 * the per-template token this contour has ({@see StorageReferenceGuard::expandTemplateIdToken()}): the
	 * published version declares `DAY_PLANNER_{token}` while the agent may name the same storage either way, and
	 * a match on text alone would answer one spelling with the columns and the other with nothing. The columns
	 * themselves are then read by the spelling they were declared in, which is how the projection indexes them.
	 *
	 * Two declared codes that expand to one storage and disagree about its columns answer with NOTHING rather
	 * than with the first of them: which of the two a value map would be judged against may not depend on the
	 * order of the published blocks (BRIEF § 3.4).
	 *
	 * Whether the caller may read the code at all is a different question and stays with the ownership gate
	 * ({@see self::authorizedLookupCode()}): this method answers what is declared, never who may see it.
	 */
	public static function publishedColumnsOf(
		BaselineProjection $baseline,
		string $storageCode,
		int $templateId,
		DocumentDescription $documentType,
	): ?SettingCollection
	{
		$lookupCode = StorageReferenceGuard::expandTemplateIdToken($storageCode, $templateId);
		if ($lookupCode === null || $lookupCode === '')
		{
			return null;
		}

		$addressed = StorageReferenceGuard::normalizeCode($lookupCode);
		$columnsByCode = $baseline->creatorStorageColumns();
		$declaration = null;
		foreach ($baseline->creatorStorageCodes() as $declaredCode)
		{
			$declaredLookupCode = StorageReferenceGuard::expandTemplateIdToken($declaredCode, $templateId);
			if (
				$declaredLookupCode === null
				|| StorageReferenceGuard::normalizeCode($declaredLookupCode) !== $addressed
			)
			{
				continue;
			}

			$declared = $columnsByCode[StorageReferenceGuard::normalizeCode($declaredCode)] ?? null;
			if ($declared === null)
			{
				continue;
			}

			if ($declaration !== null && $declaration !== $declared)
			{
				return null;
			}

			$declaration = $declared;
		}

		return self::columnsOfDeclaration($declaration, $documentType);
	}

	/**
	 * The settings with every dynamic map the portal could not name the keys of answered by these declared
	 * columns, and every other setting left as it is.
	 *
	 * The reading of the portal keeps its precedence: a map that already carries children names the columns the
	 * storage has TODAY, and a declaration may not overrule them (BRIEF § 3.4, source 2 before source 3).
	 */
	public static function withDeclaredKeys(SettingCollection $settings, SettingCollection $declared): SettingCollection
	{
		$resolved = new SettingCollection();
		foreach ($settings as $setting)
		{
			/** @var Setting $setting */
			$resolved->add(
				$setting->keyMode === SettingKeyMode::Dynamic && $setting->children === null
					? self::withChildren($setting, $declared)
					: $setting
			);
		}

		return $resolved;
	}

	/**
	 * A declared column list read as the child schema of the value map, through the two steps the activity
	 * itself reads a real storage by ({@see StorageField::toProperty()} and
	 * {@see ActivityAiPropertyConverter::convertMap()}), so a column means the same thing whichever source named
	 * it.
	 *
	 * A column the converter cannot express still names a key of the storage and is kept as a plain one: the
	 * type registry of the portal does not have to know a type for the column to exist - a shipped agent
	 * declares a column of a type no registry carries - and dropping it would refuse the very value the
	 * published template writes into it.
	 */
	private static function columnsOfDeclaration(mixed $declaration, DocumentDescription $documentType): ?SettingCollection
	{
		if (!is_array($declaration) || !array_is_list($declaration) || $declaration === [])
		{
			return null;
		}

		$properties = [];
		foreach ($declaration as $column)
		{
			if (!is_array($column))
			{
				continue;
			}

			try
			{
				$property = StorageField::mapFromArray($column)->toProperty();
			}
			catch (\Throwable)
			{
				continue;
			}

			$code = (string)($property['FieldName'] ?? '');
			if ($code !== '')
			{
				$properties[$code] = $property;
			}
		}

		if ($properties === [])
		{
			return null;
		}

		$children = (new ActivityAiPropertyConverter())->convertMap($properties, $documentType->toBizprocComplexType());
		foreach ($properties as $code => $property)
		{
			if ($children->findFirstByName((string)$code) === null)
			{
				$children->add(new Setting(
					name: (string)$code,
					description: (string)($property['Name'] ?? $code),
					type: ActivityAiPropertyConverter::settingTypeOf(FieldType::STRING),
					required: (bool)($property['Required'] ?? false),
					multiple: (bool)($property['Multiple'] ?? false),
				));
			}
		}

		return $children;
	}

	/**
	 * What the bound template owns and declares, in the spelling the runtime addresses storage by - the very
	 * context the catalog path builds ({@see AgentBlockCatalogService::publishedStorageAuthorization()}), so a
	 * code either path may be handed is read by one rule in one form. Ownership covers the create nodes of the
	 * graph and of the published version; the declared set adds the codes the published version merely names,
	 * whose storage the setup wizard or a person may have made.
	 *
	 * Built once per run: its constructor indexes both code sets - a regexp per code - which a per-node instance
	 * would repeat for each of the 256 blocks an entry may carry.
	 */
	private function authorization(): StorageAuthorizationContext
	{
		return $this->authorization ??= self::expandedAuthorizationOf(
			$this->templateId(),
			$this->context?->baselineStorageCodes ?? [],
			$this->context?->ownedStorageCodes ?? [],
		);
	}

	private function templateId(): int
	{
		return $this->context?->templateId ?? 0;
	}

	/**
	 * The one reading of "this template may address this storage", shared with the catalog read path: the
	 * columns of a storage are the same secret whichever path answers with them, and a second definition of
	 * ownership would let one path publish what the other refuses
	 * ({@see AgentBlockContextResolver::authorizedStorageReference()}).
	 */
	public static function ownsOrDeclares(StorageAuthorizationContext $authorization, string $storageCode): bool
	{
		return $authorization->ownsStorageCode($storageCode) || $authorization->declaresStorageCode($storageCode);
	}

	/**
	 * The guard context of a template that owns and declares these codes. Node fingerprints stay empty: they
	 * authorise the byte-wise passthrough of a published consumer node, and neither this resolution nor a
	 * description context is one.
	 *
	 * @param list<string> $declaredCodes codes the published version declares
	 * @param list<string> $ownedCodes codes the template itself creates
	 */
	public static function authorizationOf(
		int $templateId,
		array $declaredCodes,
		array $ownedCodes,
	): StorageAuthorizationContext
	{
		return new StorageAuthorizationContext(
			templateId: $templateId,
			baselineStaticNodeFingerprints: [],
			baselineStorageCodes: $declaredCodes,
			ownedStorageCodes: $ownedCodes,
		);
	}

	/**
	 * The same context with both sets expanded to the code the RUNTIME addresses storage by, for the caller that
	 * cannot know which spelling of a code it will be handed.
	 *
	 * A per-template code is the canonical form - the create-node admission policy requires the
	 * `{=Workflow:TemplateId}` substitution - so the published version declares `DAY_PLANNER_{token}` while the
	 * storage that exists is `DAY_PLANNER_198`. Comparing the two as text authorises neither spelling: the
	 * expanded one is not in the set, and the token one is in it but finds no storage when it is looked up. Both
	 * sides are therefore expanded through the one reading of the token this contour has
	 * ({@see StorageReferenceGuard::expandTemplateIdToken()}), which is the same reading
	 * {@see self::describe()} looks storage up by - so the catalog and the graph path resolve a code to the very
	 * same storage.
	 *
	 * Nothing widens: a code the barrier cannot read whole expands to null and is dropped, and the token expands
	 * with the id of the BOUND template alone, so it can only ever name storage of that template.
	 *
	 * @param list<string> $declaredCodes codes the published version declares, as written
	 * @param list<string> $ownedCodes codes the template itself creates, as written
	 */
	public static function expandedAuthorizationOf(
		int $templateId,
		array $declaredCodes,
		array $ownedCodes,
	): StorageAuthorizationContext
	{
		return self::authorizationOf(
			$templateId,
			self::expandCodes($declaredCodes, $templateId),
			self::expandCodes($ownedCodes, $templateId),
		);
	}

	/**
	 * The code a block is described by when the bound template may address the storage it names, or null when it
	 * may not - one answer for both questions, because the spelling that authorises and the spelling that finds
	 * the storage have to be the same one.
	 *
	 * Either spelling is accepted: the agent was handed the code in the form the published template writes it
	 * (the token one) and may send that back, while the expanded one is what the storage really carries. Both
	 * expand to the same text, which is what the description is then built with.
	 */
	public static function authorizedLookupCode(
		StorageAuthorizationContext $authorization,
		string $storageCode,
		int $templateId,
	): ?string
	{
		$lookupCode = StorageReferenceGuard::expandTemplateIdToken($storageCode, $templateId);
		if ($lookupCode === null || $lookupCode === '')
		{
			return null;
		}

		return self::ownsOrDeclares($authorization, $lookupCode) ? $lookupCode : null;
	}

	/**
	 * @param list<string> $codes
	 * @return list<string> the codes the barrier reads whole, expanded; the rest are dropped rather than kept as
	 *         text, because a code whose expansion cannot be predicted authorises nothing anyway
	 */
	private static function expandCodes(array $codes, int $templateId): array
	{
		$expanded = [];
		foreach ($codes as $code)
		{
			$lookupCode = StorageReferenceGuard::expandTemplateIdToken($code, $templateId);
			if ($lookupCode !== null && $lookupCode !== '')
			{
				$expanded[] = $lookupCode;
			}
		}

		return $expanded;
	}

	/**
	 * The published block description in the shape the context gateway judges a sent value against. The gateway
	 * answers for what the agent was given, and the block detail is that very description carried one layer up.
	 */
	private static function asDescriptionResult(string $blockType, BlockTypeDetail $detail): ActivityAiDescriptionResult
	{
		return new ActivityAiDescriptionResult(
			code: $blockType,
			settings: $detail->settings,
			skippedSettings: $detail->skippedSettings,
			carriedSettings: $detail->carriedSettings,
			settingConstraints: $detail->settingConstraints,
		);
	}
}

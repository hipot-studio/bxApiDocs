<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\BaselineProjection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SetupTemplateNodeMatcher;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext;
use Bitrix\BizprocDesigner\Internal\Service\Container;

/**
 * Assembles the {@see ValidationContext} identically on all three reverse entries (REST validate/push and Marta),
 * so validate == push == Marta (TPL-02). The storage baseline comes from the *published* bound template
 * ({@see AgentWorkflowResolverService::getPublishedAgentTemplate}) - the draft resolver would make the enforcement
 * circular - and is run through the same forward converter (RequestSource::Rest) the agent's template.get uses, so
 * an unchanged round-trip node fingerprints identically (ALG-01):
 *   - IMMUTABLE_TYPES (create/setuptemplate) -> baselineImmutableFingerprints, unconditionally (setuptemplate
 *     carries no StorageCode, so its immutability must not depend on one). Answered by the projection below
 *     rather than collected here (ALG-02), as are the published start-trigger instances;
 *   - CONSUMER_TYPES (read/write/delete) with a static code, with a code the barrier cannot read whole, or
 *     addressed by StorageId -> baselineStaticNodeFingerprints and baselineStaticNodeReferences, so the guard can
 *     let an unchanged id-addressed node through its anti-IDOR rule (ALG-02 rule 1 -> rule 4). Answered by the
 *     projection below rather than collected here (ALG-02);
 *   - storage codes the published template declares -> baselineStorageCodes, which is what authorises a token
 *     code (ALG-02 rule 3), and the codes of its create nodes alone -> baselineCreatorStorageCodes, the narrower
 *     set the admission policy inherits from (ALG-10). Both answered by the projection below as well;
 *   - restricted passthrough settings of any node type, and of the sub-steps a complex node carries in its rules
 *     -> baselineRestrictedSettingFingerprints, which is what lets an executable condition be carried through but
 *     never authored (ALG-04). Both levels are collected because both are judged. The graph the agent was handed
 *     feeds the same rule through a set of its own, see below.
 *   - every (block type, preset, document type, setting name, value) tuple the baseline carries, top-level and
 *     nested alike -> baselineOptionValues ({@see ValidationContext::hasCarriedOptionValue()}). Every part of the
 *     key is a part the option list is built from: without the preset a value published in the deal variant of a
 *     trigger would unlock it in the lead variant, and without the document type a status published in the sub-step
 *     of a deal complex node would unlock it in the sub-step of a lead one - the same sub-step class sits in both
 *     dictionaries. The document type is named at the nested level alone, where it is the host node's effective one;
 *     a block of the graph is described for the type of the whole request and names none. Collected for every
 *     setting rather than for the described ones alone: the passthrough only ever applies inside the option check,
 *     and asking the catalog to describe each baseline block would cost a lookup per node for nothing.
 *   - the types the baseline is built of -> baselineBlockTypes, against which the reverse path lifts the EXCLUDED
 *     barrier of a reverse-visible CLASS: a switched-off block the template already published reads back, while
 *     one the agent invents does not. Answered by the projection below rather than collected here (ALG-02).
 *   - constants the published template declares -> baselineConstants, whose keys are the wider ownership set a
 *     code is judged taken against, and the codes the setup-wizard nodes of that same published version declare
 *     -> baselineWizardCodes, the narrow set a constant counts as inherited by (ALG-01). The two are kept apart
 *     because declaring a code outside the wizard proves the constant exists but gives the wizard no right to
 *     redefine it. The narrow set is collected by reading the item codes of the baseline forms rather than by
 *     parsing them: the sample is already published, so only its codes are of interest here.
 *   - the presence of the published version itself -> hasPublishedBaseline, taken from the baseline graph and never
 *     from the emptiness of those two sets: a published template that has declared no constant yet is a baseline
 *     all the same, and the wizard admission has to let a first constant into it.
 *   - wizard-authored codes the bound DRAFT already carries -> draftWizardCodes, the one reading here that does not
 *     come from the published version. It authorises nothing and is measured against the declaration ceiling alone:
 *     a reverse write merges declarations into the draft and never removes one, so a ceiling that saw the published
 *     version only would watch the draft grow without a bound.
 *   - the published graph itself, under the one matching rule of ALG-02 -> baseline
 *     ({@see BaselineProjection}). The sets above answer "is this payload published anywhere in the template",
 *     each for its own class of payload; the projection answers "which published block is this block", which is
 *     what all of them are being carried over onto. Assembled from $publishedTemplate and never from the handed
 *     graph below, which is kept a projection of its own. What stays collected HERE rather than in the projection is
 *     what has to ask the portal something - the restricted names of an activity, the document type a complex
 *     node fixes, the parse of a wizard form - because this is where the contour keeps its collaborators.
 *   - every activity name the RAW published template carries, at any depth -> baselinePublishedNames
 *     ({@see ValidationContext::$baselinePublishedNames}). The agent projection has no name for the child of a
 *     sub-action at all, while the runtime resolves a reference to one by name, so this reading cannot come from
 *     the converted graph the other baselines are built of.
 *
 * Three readings do NOT come from the published version, and all of them mean the same thing: the agent has to be
 * able to hand back the graph it was given. The option values of that graph -> draftOptionValues
 * ({@see ValidationContext::$draftOptionValues}); the graph itself under the matching rule -> handed
 * ({@see ValidationContext::$handed}); and its restricted passthrough settings, top-level and nested alike ->
 * handedRestrictedSettingFingerprints and handedRestrictedSettingReferences
 * ({@see ValidationContext::$handedRestrictedSettingFingerprints}). Each is narrowed to what the published version
 * does not already carry, so one key is accounted for once, and each is named after the graph it comes from: the
 * storage baselines, the block-type baseline and the admission of an undescribed node stay on the published version,
 * or a graph would authorise itself through the draft it wrote.
 *
 * The restricted settings are the one of the three a barrier rests on, and they rest on it because the barrier has
 * to accept back what `template.get` handed over: a condition a person wrote in the editor lives in the draft alone,
 * and the agent has neither the right to author it nor a value to replace it with. The circle that would make this
 * unsound does not close - the barrier stands on the write path of the contour too, so the agent cannot put an
 * authored payload into the draft and collect its authorisation on the next call (ALG-04).
 *
 * The baseline is assembled on the Marta entry as well, although storage nodes are hidden from it: one graph must
 * get one verdict on every entry, so the extra published-template conversion is an accepted cost.
 */
final class ValidationContextFactory
{
	private readonly AgentWorkflowResolverService $resolverService;

	public function __construct(?AgentWorkflowResolverService $resolverService = null)
	{
		$this->resolverService = $resolverService ?? Container::getAgentWorkflowResolverService();
	}

	public function create(
		WorkflowTemplateIdentifier $identifier,
		RequestSource $source,
		int $userId,
		bool $isUserAdmin,
	): ValidationContext
	{
		$publishedTemplate = $identifier->templateId !== null
			? $this->resolverService->getPublishedAgentTemplate($identifier->templateId)
			: null;

		$publishedConstants = $identifier->templateId !== null
			? $this->resolverService->getPublishedTemplateConstants($identifier->templateId)
			: [];

		// The names of the RAW published tree, which the agent projection above cannot answer: a complex node's
		// sub-action carries children the runtime resolves by name and the projection never names
		// ({@see ValidationContext::$baselinePublishedNames}).
		$publishedNames = $identifier->templateId !== null
			? $this->resolverService->getPublishedTemplateActivityNames($identifier->templateId)
			: [];

		// The draft is read for its SIZE alone (see ValidationContext::$draftWizardCodes): the baselines an edit is
		// judged against stay the published version's, or a graph would vouch for itself through the draft it wrote.
		$draftWizardCodes = $identifier->draftId !== null
			? self::wizardAuthoredCodes($this->resolverService->getDraftConstants($identifier->draftId))
			: [];

		// The graph the agent was HANDED, which is the draft whenever the template has one. It feeds what the agent
		// must be able to send back unchanged: the option-list passthrough (see ValidationContext::$draftOptionValues),
		// the matching rule of its own (see ValidationContext::$handed) and the restricted passthrough settings it
		// carries (see ValidationContext::$handedRestrictedSettingFingerprints). Read only when a draft exists -
		// without one this is the published version already converted above, and a second conversion of it would buy
		// nothing.
		$handedTemplate = $identifier->draftId !== null
			? $this->resolverService->getAgentTemplate($identifier)
			: null;

		return self::fromBaselineTemplate(
			$publishedTemplate,
			$identifier->documentDescription,
			$identifier->templateId,
			$source,
			$userId,
			$isUserAdmin,
			publishedConstants: $publishedConstants,
			draftWizardCodes: $draftWizardCodes,
			handedTemplate: $handedTemplate,
			publishedNames: $publishedNames,
		);
	}

	/**
	 * The baseline formula itself, split off the resolver lookup so a caller that already holds the baseline graph
	 * assembles the context through the very same code the request path uses.
	 *
	 * @param array<string, array> $publishedConstants CONSTANTS of the published template, as written
	 * @param list<string> $draftWizardCodes wizard-authored codes the bound draft already carries, for the
	 *        declaration ceiling alone ({@see ValidationContext::$draftWizardCodes})
	 * @param AgentTemplate|null $handedTemplate the graph template.get answered the agent with - the bound draft when
	 *        the template has one. Read for what the agent must be able to send back unchanged - its option values
	 *        ({@see ValidationContext::$draftOptionValues}), its own matching rule ({@see ValidationContext::$handed})
	 *        and its restricted passthrough settings
	 *        ({@see ValidationContext::$handedRestrictedSettingFingerprints}) - and for nothing else. Every set it
	 *        feeds is named after it and stays apart from the baseline one: the storage baselines, the block-type
	 *        baseline and the admission of an undescribed node are assembled from the published version alone, or a
	 *        graph would anchor itself in the draft it wrote
	 * @param array<string, true> $publishedNames every activity name the published template carries at any depth
	 *        ({@see ValidationContext::$baselinePublishedNames}), read from the raw template rather than from its
	 *        agent projection, which has no name for the child of a sub-action at all
	 */
	public static function fromBaselineTemplate(
		?AgentTemplate $publishedTemplate,
		DocumentDescription $documentType,
		?int $templateId,
		RequestSource $source,
		int $userId = 0,
		bool $isUserAdmin = false,
		?RestrictedSettingRegistry $restrictedSettings = null,
		array $publishedConstants = [],
		array $draftWizardCodes = [],
		?AgentTemplate $handedTemplate = null,
		array $publishedNames = [],
	): ValidationContext
	{
		$restrictedSettings ??= new RestrictedSettingRegistry();
		$baselineRestrictedSettingFingerprints = [];
		$baselineRestrictedSettingReferences = [];
		$baselineWizardCodes = [];
		$baselineOptionValues = [];

		if ($publishedTemplate !== null)
		{
			foreach ($publishedTemplate->blocks as $block)
			{
				/** @var AgentBlock $block */
				$baselineRestrictedSettingFingerprints += self::collectRestrictedSettings(
					$block,
					$documentType,
					$restrictedSettings,
				);
				$baselineRestrictedSettingReferences += self::collectRestrictedSettingReferences(
					$block,
					$documentType,
					$restrictedSettings,
				);
				$baselineOptionValues += self::collectOptionValues($block, $documentType);

				if (SetupTemplateNodeMatcher::matches($block->type))
				{
					$wizardCodes = self::readWizardConstantCodes($block->settings);
					if ($wizardCodes === null)
					{
						// One unreadable baseline form settles the whole narrow set: a partial one would pass an
						// inherited constant off as a new one.
						$baselineWizardCodes = null;
					}
					elseif ($baselineWizardCodes !== null)
					{
						$baselineWizardCodes = [...$baselineWizardCodes, ...$wizardCodes];
					}
				}
			}
		}

		// Only what the published version does NOT already carry: the check reads the two maps as one
		// ({@see ValidationContext::hasCarriedOptionValue()}), so a tuple in both would answer twice and be held
		// twice - and the handed graph is the draft, whose width the agent writes itself. Its restricted passthrough
		// settings are collected the same way and by the same code as the published ones, because the barrier they
		// feed must judge a draft value by exactly the rule it judges a published one by (ALG-04).
		$draftOptionValues = [];
		$handedRestrictedSettingFingerprints = [];
		$handedRestrictedSettingReferences = [];
		if ($handedTemplate !== null)
		{
			foreach ($handedTemplate->blocks as $block)
			{
				/** @var AgentBlock $block */
				$draftOptionValues += array_diff_key(
					self::collectOptionValues($block, $documentType),
					$baselineOptionValues,
				);
				$handedRestrictedSettingFingerprints += array_diff_key(
					self::collectRestrictedSettings($block, $documentType, $restrictedSettings),
					$baselineRestrictedSettingFingerprints,
				);
				$handedRestrictedSettingReferences += array_diff_key(
					self::collectRestrictedSettingReferences($block, $documentType, $restrictedSettings),
					$baselineRestrictedSettingReferences,
				);
			}
		}

		return new ValidationContext(
			documentType: $documentType,
			templateId: $templateId,
			source: $source,
			userId: $userId,
			isUserAdmin: $isUserAdmin,
			baselineRestrictedSettingFingerprints: $baselineRestrictedSettingFingerprints,
			baselineConstants: $publishedConstants,
			baselineWizardCodes: $baselineWizardCodes === null
				? null
				: array_values(array_unique($baselineWizardCodes)),
			draftWizardCodes: $draftWizardCodes,
			// The baseline graph itself answers whether anything is published, so a published template that declares
			// no constant yet is told apart from a template with no published version at all.
			hasPublishedBaseline: $publishedTemplate !== null,
			baselineRestrictedSettingReferences: $baselineRestrictedSettingReferences,
			baselineOptionValues: $baselineOptionValues,
			draftOptionValues: $draftOptionValues,
			// The published graph itself, projected under the one matching rule of ALG-02. Built from
			// $publishedTemplate and from nothing else: the handed graph below is the draft, and a baseline
			// carrying it would let a graph excuse itself by what it wrote.
			baseline: new BaselineProjection($publishedTemplate?->blocks ?? []),
			baselinePublishedNames: $publishedNames,
			// The handed graph under the same matching rule, kept apart from the baseline at every point of use
			// ({@see ValidationContext::$handed}).
			handed: new BaselineProjection($handedTemplate?->blocks ?? []),
			handedRestrictedSettingFingerprints: $handedRestrictedSettingFingerprints,
			handedRestrictedSettingReferences: $handedRestrictedSettingReferences,
		);
	}

	/**
	 * Every value the block holds, its own and those of the sub-steps a complex node carries in its rules, keyed by
	 * what the option list of each level is built from. The block's own values name no document type: a block is
	 * described for the type of the edited template, one and the same for the whole map
	 * ({@see ValidationContext::optionValueFingerprint()}). A sub-step's values are keyed by the activity the
	 * construction names rather than by the host node, with no preset and with the host node's EFFECTIVE document
	 * type, exactly as the nested check asks its schema ({@see self::constructionOptionValues()}).
	 *
	 * @return array<string, true>
	 */
	private static function collectOptionValues(AgentBlock $block, DocumentDescription $documentType): array
	{
		$values = [];
		foreach ($block->settings as $setting)
		{
			$values += self::optionValueFingerprints(
				$block->type,
				$block->presetId,
				$setting->name,
				$setting->value,
				documentType: null,
			);
		}

		return $values + self::collectNestedOptionValues($block, $documentType);
	}

	/** @return array<string, true> */
	private static function collectNestedOptionValues(AgentBlock $block, DocumentDescription $documentType): array
	{
		if ($block->rules === null)
		{
			return [];
		}

		$nestedDocumentType = self::nestedDocumentTypeOf($block->type, $documentType);
		if ($nestedDocumentType === null)
		{
			// No effective document type means no sub-step schema at all
			// ({@see ComplexBlockDetailFactory::describeSubAction()}), so the nested option check never runs and a
			// key collected here could only ever sit unread.
			return [];
		}

		$values = [];
		foreach ($block->rules->portRules as $portRuleList)
		{
			foreach ($portRuleList as $rule)
			{
				foreach ($rule->constructions as $construction)
				{
					$values += self::constructionOptionValues($construction, $nestedDocumentType);
				}
			}
		}

		return $values;
	}

	/**
	 * The document type the sub-steps of one host node are described for: the type its complex activity fixes, the
	 * type of the edited template when it fixes none. Asked of the same two services the DESCRIBING side of this
	 * contour asks ({@see ComplexBlockDetailFactory::build()}), so the key a value is collected under is the key
	 * the check looks it up by.
	 *
	 * It is not the question the editor asks, though. Both ends of this contour read
	 * {@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getFixedDocumentTypeForNodeAction()},
	 * while the surfaces of the editor read `getPublishedDocumentTypeForNode($type, $properties)`, which at a
	 * trigger also answers with the document type of the event. The two diverge on exactly six shipped triggers -
	 * `crm{company,contact,deal,lead,quote,smartinvoice}manualstarttrigger` - where the fixed type is null and the
	 * published one names a real CRM type. No graph reaches that divergence today: a host node has a detail only
	 * when its NODE_TYPE is `complex` ({@see ComplexBlockDetailFactory::build()}) and none of the six declares one,
	 * so this contour stays consistent with itself. Written down because the difference is invisible from here.
	 *
	 * It cannot be left out of the key and it cannot be replaced by the type of the request: one template may hold
	 * a deal complex node and a lead one, both offering the same sub-step, and their option lists differ. Reading
	 * this per host node costs a lookup for complex nodes alone - a plain block never reaches here.
	 */
	private static function nestedDocumentTypeOf(string $blockType, DocumentDescription $documentType): ?array
	{
		$fixedDocumentType = BizprocContainer::instance()
			->getComplexActivityService()
			->getFixedDocumentTypeForNodeAction($blockType)
		;

		return Container::getNodeFilterAvailability()->resolveEffectiveDocumentType(
			$fixedDocumentType,
			$documentType->toBizprocComplexType(),
		);
	}

	/**
	 * The values of one sub-step, keyed with NO preset and with the effective document type of its host node. The
	 * absent preset is the whole of it rather than an omission: a sub-step carries none, and its option list is a
	 * function of its activity code and that document type alone
	 * ({@see ComplexBlockDetailFactory::describeSubAction()}). The nested check keys its lookup exactly the same way
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentComplexRulesValidator::validateNestedSettingNames()}).
	 *
	 * @return array<string, true>
	 */
	private static function constructionOptionValues(
		AgentComplexConstruction $construction,
		array $nestedDocumentType,
	): array
	{
		if (
			$construction->type !== AgentComplexConstruction::TYPE_ACTION
			&& $construction->type !== AgentComplexConstruction::TYPE_FILTER
		)
		{
			return [];
		}

		$activityCode = $construction->expression['activityCode'] ?? null;
		$settings = $construction->expression['settings'] ?? null;
		if (!is_string($activityCode) || $activityCode === '' || !is_array($settings))
		{
			return [];
		}

		$values = [];
		foreach ($settings as $name => $value)
		{
			if (is_string($name) && $name !== '')
			{
				$values += self::optionValueFingerprints(
					$activityCode,
					presetId: null,
					name: $name,
					value: $value,
					documentType: $nestedDocumentType,
				);
			}
		}

		return $values;
	}

	/**
	 * A multiple setting holds its values in a list, and the option check judges each element on its own, so the
	 * baseline is collected per element too, by the predicate the option check itself applies: a scalar is judged
	 * by its string form, and an element that has none is refused there whatever the baseline holds, so a
	 * fingerprint of one is never asked for.
	 *
	 * An element that is an object is the map shape, and its members are judged by their own schema as well
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingValueValidator::validateChildValue()}).
	 * They are collected under the CHILD key of ALG-02 - the setting name and the key inside it - because that is
	 * how the check looks them up, and never under the position in the list: an agent that reorders the columns of
	 * a storage node has changed nothing about the value of any of them. Without this a published column whose type
	 * the portal has since stopped offering would be refused on re-send, which is the round-trip of an untouched
	 * template.
	 *
	 * @return array<string, true>
	 */
	private static function optionValueFingerprints(
		string $blockType,
		?string $presetId,
		string $name,
		mixed $value,
		?array $documentType,
	): array
	{
		$fingerprints = [];
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			if (is_scalar($item))
			{
				$fingerprints[ValidationContext::optionValueFingerprint(
					$blockType,
					$presetId,
					$name,
					(string)$item,
					$documentType,
				)] = true;

				continue;
			}

			if (is_array($item))
			{
				// One element of a map that is also multiple: a list of objects.
				$fingerprints += self::childOptionValueFingerprints(
					$blockType,
					$presetId,
					$name,
					$item,
					$documentType,
				);
			}
		}

		if (is_array($value) && !array_is_list($value))
		{
			// A map that is not multiple: the value IS the object, and its members are the children.
			$fingerprints += self::childOptionValueFingerprints(
				$blockType,
				$presetId,
				$name,
				$value,
				$documentType,
			);
		}

		return $fingerprints;
	}

	/**
	 * The members of one object of a map setting, keyed by {@see ValidationContext::childSettingName()}. Only one
	 * level down: the schema of a map declares children and no grandchildren, so nothing below is ever judged
	 * against an option list.
	 *
	 * @param array<array-key, mixed> $object
	 *
	 * @return array<string, true>
	 */
	private static function childOptionValueFingerprints(
		string $blockType,
		?string $presetId,
		string $name,
		array $object,
		?array $documentType,
	): array
	{
		$fingerprints = [];
		foreach ($object as $key => $childValue)
		{
			if (!is_string($key) || $key === '')
			{
				continue;
			}

			foreach (is_array($childValue) ? $childValue : [$childValue] as $item)
			{
				if (is_scalar($item))
				{
					$fingerprints[ValidationContext::optionValueFingerprint(
						$blockType,
						$presetId,
						ValidationContext::childSettingName($name, $key),
						(string)$item,
						$documentType,
					)] = true;
				}
			}
		}

		return $fingerprints;
	}

	/**
	 * Codes of the constant items one baseline wizard form declares. The public parser is the single account of a
	 * readable form, so a baseline with an unknown item or an incomplete constant fails closed here too.
	 *
	 * Never returns an empty list for an unreadable form: an unreadable sample read as "declares nothing" would send
	 * an inherited constant down the branch of a new one (see {@see ValidationContext::__construct} for that refusal).
	 *
	 * @return list<string>|null
	 */
	private static function readWizardConstantCodes(AgentSettingCollection $settings): ?array
	{
		$parsed = SetupTemplateNodePolicy::parseNodeForm(
			SetupTemplateNodePolicy::readRawForm($settings),
		);
		if ($parsed === null || !$parsed->isSuccess())
		{
			return null;
		}

		$codes = [];
		foreach ($parsed->declarations as $declaration)
		{
			$codes[] = $declaration->code;
		}

		return $codes;
	}

	/**
	 * Codes of the constants a wizard authored, out of the ones a draft carries. Told apart by the code form the
	 * wizard editor produces ({@see SetupTemplateNodePolicy::isEditorAuthoredCode()}) - the very form the admission
	 * of a new constant demands - so a constant the owner declared elsewhere in the template is not counted against
	 * a ceiling that is about the wizard.
	 *
	 * @param array<string, mixed> $constants
	 *
	 * @return list<string>
	 */
	private static function wizardAuthoredCodes(array $constants): array
	{
		$codes = [];
		foreach (array_keys($constants) as $code)
		{
			if (SetupTemplateNodePolicy::isEditorAuthoredCode((string)$code))
			{
				$codes[] = (string)$code;
			}
		}

		return $codes;
	}

	/**
	 * Restricted passthrough settings of one block of a graph the server wrote (ALG-04) - its own and those of the
	 * sub-steps a complex node carries in its rules. Anchored per (name, value) rather than per node, so an unrelated
	 * edit on the same block - a new title, a moved connection - does not cost the template a condition it already
	 * carries. Run over the published version and over the handed graph alike, each into a set of its own, so one
	 * account of the key serves both.
	 *
	 * @return array<string, true>
	 */
	private static function collectRestrictedSettings(
		AgentBlock $block,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		$fingerprints = [];

		$restrictedNames = $restrictedSettings->namesFor($block->type, $documentType);
		if ($restrictedNames !== [])
		{
			foreach ($block->settings as $setting)
			{
				if (in_array(mb_strtolower($setting->name), $restrictedNames, true))
				{
					$fingerprints[RestrictedSettingRegistry::fingerprintOf($setting->name, $setting->value)] = true;
				}
			}
		}

		return $fingerprints + self::collectNestedRestrictedSettings($block, $documentType, $restrictedSettings);
	}

	/** @return array<string, true> */
	private static function collectRestrictedSettingReferences(
		AgentBlock $block,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		$references = [];
		$restrictedNames = $restrictedSettings->namesFor($block->type, $documentType);
		foreach ($block->settings as $setting)
		{
			if (in_array(mb_strtolower($setting->name), $restrictedNames, true))
			{
				$references[RestrictedSettingRegistry::referenceFingerprint(
					"block:{$block->id}",
					$block->type,
					$setting->name,
					$setting->value,
				)] = true;
			}
		}

		return $references + self::collectNestedRestrictedSettingReferences($block, $documentType, $restrictedSettings);
	}

	/** @return array<string, true> */
	private static function collectNestedRestrictedSettingReferences(
		AgentBlock $block,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		if ($block->rules === null)
		{
			return [];
		}

		$references = [];
		foreach ($block->rules->portRules as $portId => $portRuleList)
		{
			foreach ($portRuleList as $rule)
			{
				foreach ($rule->constructions as $constructionIndex => $construction)
				{
					$references += self::constructionRestrictedSettingReferences(
						$construction,
						"block:{$block->id}|port:{$portId}|rule:{$rule->id}|construction:{$constructionIndex}",
						$documentType,
						$restrictedSettings,
					);
				}
			}
		}

		return $references;
	}

	/** @return array<string, true> */
	private static function constructionRestrictedSettingReferences(
		AgentComplexConstruction $construction,
		string $location,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		if (
			$construction->type !== AgentComplexConstruction::TYPE_ACTION
			&& $construction->type !== AgentComplexConstruction::TYPE_FILTER
		)
		{
			return [];
		}

		$activityCode = $construction->expression['activityCode'] ?? null;
		$settings = $construction->expression['settings'] ?? null;
		if (!is_string($activityCode) || $activityCode === '' || !is_array($settings) || $settings === [])
		{
			return [];
		}

		$references = [];
		$restrictedNames = $restrictedSettings->namesFor($activityCode, $documentType);
		foreach ($settings as $name => $value)
		{
			if (is_string($name) && in_array(mb_strtolower($name), $restrictedNames, true))
			{
				$references[RestrictedSettingRegistry::referenceFingerprint($location, $activityCode, $name, $value)] = true;
			}
		}

		return $references;
	}


	/**
	 * The nested half of the sample, collected because the barrier reaches that level too
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentComplexRulesValidator}): were the
	 * sub-steps left out here, one returned unchanged from the published version would find no fingerprint of its own
	 * and be refused as newly authored - the round-trip of an untouched template, not an attack, is what would break.
	 *
	 * Names are asked of the activity each construction names, never of the host node, exactly as the nested check
	 * asks them: a sub-step is a different class with restrictions of its own.
	 *
	 * @return array<string, true>
	 */
	private static function collectNestedRestrictedSettings(
		AgentBlock $block,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		if ($block->rules === null)
		{
			return [];
		}

		$fingerprints = [];
		foreach ($block->rules->portRules as $portRuleList)
		{
			foreach ($portRuleList as $rule)
			{
				foreach ($rule->constructions as $construction)
				{
					$fingerprints += self::constructionRestrictedSettings(
						$construction,
						$documentType,
						$restrictedSettings,
					);
				}
			}
		}

		return $fingerprints;
	}

	/**
	 * Restricted settings of one sub-step. Only `action` and `filter` carry an activity of their own - the very two
	 * kinds the nested check authorises; a condition or an output has no settings map to judge.
	 *
	 * @return array<string, true>
	 */
	private static function constructionRestrictedSettings(
		AgentComplexConstruction $construction,
		DocumentDescription $documentType,
		RestrictedSettingRegistry $restrictedSettings,
	): array
	{
		if (
			$construction->type !== AgentComplexConstruction::TYPE_ACTION
			&& $construction->type !== AgentComplexConstruction::TYPE_FILTER
		)
		{
			return [];
		}

		$activityCode = $construction->expression['activityCode'] ?? null;
		$settings = $construction->expression['settings'] ?? null;
		if (!is_string($activityCode) || $activityCode === '' || !is_array($settings) || $settings === [])
		{
			return [];
		}

		$restrictedNames = $restrictedSettings->namesFor($activityCode, $documentType);
		if ($restrictedNames === [])
		{
			return [];
		}

		$fingerprints = [];
		foreach ($settings as $name => $value)
		{
			if (is_string($name) && in_array(mb_strtolower($name), $restrictedNames, true))
			{
				$fingerprints[RestrictedSettingRegistry::fingerprintOf($name, $value)] = true;
			}
		}

		return $fingerprints;
	}

}

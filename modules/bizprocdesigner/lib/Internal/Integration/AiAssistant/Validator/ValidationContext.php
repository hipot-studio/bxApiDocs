<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry;

/**
 * Reverse-validation context threaded through SaveWorkflowValidator -> AgentBlocksValidator -> AgentBlockValidator
 * (TPL-02). Assembled once per request by {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory}
 * on every entry (REST validate/push and Marta), so validate == push == Marta. The storage baselines come from
 * the *published* bound template (never the agent draft), so a forged storage node cannot anchor itself. So does
 * the block-type baseline, and so does every admission of a node the catalog does not describe.
 *
 * The readings taken from the graph the agent was HANDED are the exception, and they are told apart from the
 * baselines at every point of use rather than merged into them. Three of them are helps to the agent - the
 * option-list passthrough ({@see self::$draftOptionValues}) and the questions asked of {@see self::$handed}. The
 * fourth feeds a barrier: the restricted passthrough settings of the handed graph
 * ({@see self::$handedRestrictedSettingFingerprints}) authorise a re-send the same way the published ones do
 * ({@see self::carriesRestrictedSettingValue()}), because `template.get` answers with the DRAFT whenever the
 * template has one and what the server handed over has to travel back. It is no circular authorisation: the agent
 * cannot author such a payload into the draft in the first place - that very barrier refuses it on every write path
 * of the contour - so a restricted value in the draft is either the published one or one a person wrote in the
 * editor.
 *
 * The subject (userId, isUserAdmin) arrives already resolved from the calling entry; the factory never re-resolves
 * it from CurrentUser, because on the Marta entry the ambient subject is not guaranteed to equal the authorized
 * one. operationKind is deliberately absent: it is per-node and resolved at the guard call site.
 */
final class ValidationContext
{
	/**
	 * Bytes of a value the key still carries as it is. The check itself judges codes - a field code, a funnel id,
	 * an enum id - and those are tens of bytes, so the bound is chosen well above the longest of them. What it
	 * catches is the other kind of value: the map is collected for every setting of the template rather
	 * than for the described ones alone, so the system prompt of an AI activity lands in it too - 23 611
	 * characters at its longest measured, the figure {@see GraphLimitsValidator::MAX_SETTING_VALUE_LENGTH} is
	 * set against.
	 */
	private const MAX_INLINE_VALUE_LENGTH = 128;

	private const INLINE_VALUE_MARK = '=';
	private const DIGESTED_VALUE_MARK = '#';

	/**
	 * Marks of the document-type part, kept apart the way the two value forms are: the level whose option list is
	 * built for the document type of the whole request writes one byte, a level that names a document type of its
	 * own writes the other. So a named type can never spell the part of the request's own type, whatever bytes it
	 * holds.
	 */
	private const OWN_DOCUMENT_TYPE_MARK = '*';
	private const NAMED_DOCUMENT_TYPE_MARK = '@';

	/** Not promoted: the constructor derives it when the assembler of the context leaves it unsaid. */
	public readonly bool $hasPublishedBaseline;

	/**
	 * How strictly this graph is judged (TPL-02). Derived from the source once, here at the entry, and read by the
	 * validators in place of the source itself: strictness is the only thing the source ever meant to them, and a
	 * condition on it spread across the catalog is what let the two entries drift apart.
	 */
	public readonly ValidationProfile $profile;

	/**
	 * Not promoted either: this set is a VIEW of {@see self::$baseline} and is answered by the projection whenever
	 * the assembler does not spell it out. It is the first of the fingerprint sets carried over onto the one
	 * matching rule (ALG-02) - the class it serves is a block type the published version already carries, and the
	 * projection holds those blocks anyway.
	 *
	 * @var array<string, true>
	 */
	public readonly array $baselineBlockTypes;

	/**
	 * A view of {@see self::$baseline} as well, and the second set carried over onto the matching rule: the class
	 * it serves is a published instance of an agent start trigger, and the projection already holds the very block
	 * the fingerprint is taken of.
	 *
	 * @var array<string, true>
	 */
	public readonly array $baselineVerbatimStartTriggerReferences;

	/**
	 * The third view of {@see self::$baseline}: the create/setuptemplate nodes the published version carries, whose
	 * settings are immutable once published. Kept a fingerprint of type and settings rather than an identity match,
	 * because an unchanged node the agent renumbered is still the published one.
	 *
	 * @var array<string, true>
	 */
	public readonly array $baselineImmutableFingerprints;

	/**
	 * Views four to seven of {@see self::$baseline}: the published storage nodes read through the node taxonomy -
	 * the anchored consumers by fingerprint and by block identity, the codes the published version declares and
	 * the narrower set its create nodes own. All four are pure readings of the published graph, which is the line
	 * the transfer to the projection stops at ({@see BaselineProjection}).
	 *
	 * @var array<string, true>
	 */
	public readonly array $baselineStaticNodeFingerprints;

	/** @var array<string, true> */
	public readonly array $baselineStaticNodeReferences;

	/** @var list<string> */
	public readonly array $baselineStorageCodes;

	/** @var list<string> */
	public readonly array $baselineCreatorStorageCodes;

	/**
	 * @param RequestSource $source origin of the write; fail-closed by default like on the read side, so a context
	 *        assembled without an explicit source cannot widen REST-only visibility. The validators mirror this
	 *        default for the case of no context at all.
	 * @param array<string, true>|null $baselineImmutableFingerprints create/setuptemplate node fingerprints; null
	 *        leaves the answer to {@see self::$baseline}, where this set is read from now
	 * @param array<string, true>|null $baselineStaticNodeFingerprints static read/write/delete consumer
	 *        fingerprints; null leaves the answer to {@see self::$baseline}, where this set is read from now
	 * @param list<string>|null $baselineStorageCodes storage codes the published template declares, as written; the
	 *        guard authorises a token code only against them (a token alone proves no ownership). null leaves the
	 *        answer to {@see self::$baseline}
	 * @param array<string, true> $baselineRestrictedSettingFingerprints (name, value) fingerprints of the
	 *        published template's restricted passthrough settings - the executable payloads an agent may carry
	 *        through but never author (ALG-04)
	 * @param list<string>|null $baselineCreatorStorageCodes codes the published template's CREATE nodes declare, as
	 *        written. Kept apart from baselineStorageCodes on purpose: that set also holds the token codes of
	 *        published consumers, whose storage the template may only ever have read, while this one answers
	 *        "the template owns this code" - the sole inheritance criterion of the admission policy (ALG-10).
	 *        null leaves the answer to {@see self::$baseline}
	 * @param list<string> $ownedStorageCodes codes this template creates for the graph under validation - the
	 *        baseline creators plus the create nodes the policy admitted. Filled by the first pass of
	 *        {@see AgentBlocksValidator} (ALG-11), never by the factory, whose subject is the published version
	 * @param array<string, array> $baselineConstants constants the published template declares, keyed by code
	 *        (ALG-01 `baselineConstants`; its keys are the wider ownership set `baselineTemplateCodes`, against
	 *        which a code is judged taken). Declaring a code proves the constant exists, nothing more: a code
	 *        declared outside the setup wizard gives the wizard no right to redefine it
	 * @param list<string>|null $baselineWizardCodes codes of the constant items the setup-wizard nodes of the
	 *        published template declare (ALG-01 `baselineWizardCodes`) - the narrow set, and the sole criterion
	 *        by which a constant counts as inherited. null means the baseline wizard form could not be read at
	 *        all, which is a refusal of its own and never an empty set: read as "declares nothing", an inherited
	 *        constant would take the branch of a new one, hit its own code as taken, and the agent - which is
	 *        given no deletion - would answer by changing the code and duplicating what it meant to edit
	 * @param list<string> $draftWizardCodes codes of the wizard-authored constants the bound draft has already
	 *        accumulated. The draft is where a reverse write merges what a graph declares, and that merge never
	 *        removes a code, so without this set the declaration ceiling would measure a template that keeps
	 *        growing against the published version alone. Counted against that ceiling and used for nothing else:
	 *        the draft authorises no edit - it would let the graph under validation vouch for itself - it only
	 *        says how much the write it feeds has already grown
	 * @param bool|null $hasPublishedBaseline whether the template has a published version at all, as the assembler
	 *        of the context knows it. Told apart from the two baseline constant sets on purpose: a published
	 *        template that has not declared a single constant yet leaves both of them empty while being a baseline,
	 *        and reading that state off their emptiness would refuse the very edit the wizard admission exists for -
	 *        adding a wizard to a template that had none. null is "the assembler did not say": the flag then falls
	 *        back to that coarse reading, so a context built by hand keeps the verdict it had before the flag
	 * @param array<string, true>|null $baselineBlockTypes block types the published template is built of, keyed
	 *        lowercase. The reverse path lifts the EXCLUDED barrier of a reverse-visible CLASS against this set,
	 *        so a switched-off block the template already published reads back while a newly authored one does
	 *        not. Coarser than a fingerprint on purpose: an agent edits the settings of the nodes it carries
	 *        through, and anchoring the barrier per node would cost every such edit its round-trip. null - the
	 *        assembler leaves it to {@see self::$baseline}, which is where this set is read from now; an explicit
	 *        set still wins, so a context built by hand keeps answering exactly what it was given
	 * @param array<string, true>|null $baselineVerbatimStartTriggerReferences exact published instances of the two
	 *        agent start triggers. Their settings are intentionally untyped, hence a type-only admission would let an
	 *        agent add, copy or alter one under the verbatim exception. null leaves the answer to
	 *        {@see self::$baseline}, where this set is read from now
	 * @param array<string, true>|null $baselineStaticNodeReferences published static storage consumers keyed by their
	 *        block identity and full storage fingerprint. The storage guard authorises the sensitive reference; this
	 *        companion map keeps an authorised fingerprint from being copied or rebound to another node id. null
	 *        leaves the answer to {@see self::$baseline}
	 * @param array<string, true> $baselineRestrictedSettingReferences executable passthrough settings keyed by their
	 *        stable top-level or nested location, activity type, name and value. The value-only baseline remains for
	 *        the local validator; this map closes cross-node reuse and duplication in the graph pass.
	 * @param array<string, true> $baselineOptionValues (block type, preset, document type, setting name, value)
	 *        tuples the published template already carries. An option list is a snapshot of the portal - a deleted
	 *        custom field or funnel drops out of it while the code stays written in the template - so a value already
	 *        published is let through the option check and only a NEW value is held to the current list. Every part
	 *        of the key is a part the list is built from
	 *        ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService},
	 *        {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ComplexBlockDetailFactory::describeSubAction()}):
	 *        without the preset a funnel id published in the deal variant of a trigger would unlock the same id in
	 *        the lead variant of the same trigger, and without the document type a status published in the sub-step
	 *        of a deal complex node would unlock it in the sub-step of a lead one. Keyed by neither node id nor node
	 *        position on purpose: the agent may rebuild the graph and renumber its nodes, while a node keeps its
	 *        preset and its document type through that rebuild, so anchoring the passthrough per node would cost an
	 *        untouched setting its round-trip
	 * @param array<string, true> $draftOptionValues the tuples of the graph the agent was actually HANDED - the bound
	 *        draft whenever the template has one ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentWorkflowResolverService::getAgentTemplate()}) -
	 *        that {@see self::$baselineOptionValues} does not already carry: the two are joined by
	 *        {@see self::hasCarriedOptionValue()}, so this map means "carried by the draft and by the published
	 *        version not", and a tuple in both would be held twice for one answer. Read by the option check alone
	 *        and by no barrier: a value the user saved into the draft and the portal has since dropped from its list
	 *        would otherwise make the graph the agent was given unreturnable, since the check judges every element
	 *        of a multiple setting. This is not a second security baseline and must not become one - the storage
	 *        baselines and the block-type baseline stay on the published version, or the agent would author a payload
	 *        into the draft and then collect its authorisation. The option list is a help to the agent rather than a
	 *        barrier, and a value can only reach the draft by passing this very check or by the hand of the user
	 * @param BaselineProjection $baseline the published version as one projection with one matching rule (ALG-02),
	 *        which is what the sets above are carried over onto set by set. It answers "which published block is
	 *        this block", the question each of them asks of its own class of payload and none of them can put; the
	 *        exception it already carries is the requiredness of an unchanged block
	 *        ({@see self::carriesBlockUnchanged()}). Assembled from the server-loaded published version alone,
	 *        exactly as every barrier here is
	 * @param array<string, true> $baselinePublishedNames every activity name the published template carries, at any
	 *        depth of its tree and keyed as written. The projection above holds the BLOCKS of the published graph,
	 *        and a complex node runs its sub-actions as children of its own: the runtime resolves a reference to
	 *        one of them by name ({@see \CBPWorkflow::getActivityByName()}) while the projection the agent is handed
	 *        carries no name for such a child at all. The shipped system agents address exactly such a child, so an
	 *        expression naming a node the SERVER wrote is not reported as a block that does not exist. Read from the
	 *        raw published template rather than from its agent projection, because that is the only place those
	 *        names exist; it authorises nothing else and is not a set of blocks
	 * @param BaselineProjection $handed the graph template.get answered the agent with, under the same matching rule
	 *        as the baseline. It is one of the readings of this context that do not come from the published version
	 *        ({@see self::$draftOptionValues} is the first), and it exists for the same reason: what the agent was
	 *        handed it must be able to hand back. Read by {@see self::carriesBlockValue()},
	 *        {@see self::carriesBlockIdentity()} and {@see self::carriesBlockUnchanged()} - helps to the agent, none
	 *        of which authorises a payload of its own. The projection is kept apart from the baseline here as it is
	 *        everywhere else: a barrier that reads the handed graph reads it as its own named set and says so
	 *        ({@see self::$handedRestrictedSettingFingerprints})
	 * @param array<string, true> $handedRestrictedSettingFingerprints (name, value) fingerprints of the restricted
	 *        passthrough settings the HANDED graph carries and {@see self::$baselineRestrictedSettingFingerprints}
	 *        does not - the two are joined by {@see self::carriesRestrictedSettingValue()}, exactly as the two
	 *        option maps are. This is the one reading of the handed graph a barrier rests on (ALG-04), and it rests
	 *        on it because the barrier would otherwise refuse the very graph `template.get` had handed over: a
	 *        condition a person wrote in the editor lives in the draft alone and the agent has no value to put
	 *        there. The agent cannot grow this set by itself - an authored payload is refused by this very barrier
	 *        on every write path of the contour, so what reaches the draft is the published value or a person's
	 *        ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory})
	 * @param array<string, true> $handedRestrictedSettingReferences the same settings of the handed graph keyed by
	 *        their location, so the location half of ALG-04 admits exactly the places the handed graph carries them
	 *        at ({@see self::carriesRestrictedSettingReference()}). Without it the value half would let a draft
	 *        condition back while the graph pass refused the node it stands on, and the round-trip would break on a
	 *        template whose published version carries a restricted setting of its own
	 */
	public function __construct(
		public readonly DocumentDescription $documentType,
		public readonly ?int $templateId,
		public readonly RequestSource $source = RequestSource::Marta,
		public readonly int $userId = 0,
		public readonly bool $isUserAdmin = false,
		?array $baselineImmutableFingerprints = null,
		?array $baselineStaticNodeFingerprints = null,
		?array $baselineStorageCodes = null,
		public readonly array $baselineRestrictedSettingFingerprints = [],
		?array $baselineCreatorStorageCodes = null,
		public readonly array $ownedStorageCodes = [],
		public readonly array $baselineConstants = [],
		public readonly ?array $baselineWizardCodes = [],
		public readonly array $draftWizardCodes = [],
		?bool $hasPublishedBaseline = null,
		?array $baselineBlockTypes = null,
		?array $baselineVerbatimStartTriggerReferences = null,
		?array $baselineStaticNodeReferences = null,
		public readonly array $baselineRestrictedSettingReferences = [],
		public readonly array $baselineOptionValues = [],
		public readonly array $draftOptionValues = [],
		public readonly BaselineProjection $baseline = new BaselineProjection(),
		public readonly array $baselinePublishedNames = [],
		public readonly BaselineProjection $handed = new BaselineProjection(),
		public readonly array $handedRestrictedSettingFingerprints = [],
		public readonly array $handedRestrictedSettingReferences = [],
	)
	{
		$this->profile = ValidationProfile::fromSource($source);
		$this->hasPublishedBaseline = $hasPublishedBaseline
			?? ($baselineConstants !== [] || $baselineWizardCodes !== [])
		;
		$this->baselineBlockTypes = $baselineBlockTypes ?? $baseline->blockTypes();
		$this->baselineVerbatimStartTriggerReferences = $baselineVerbatimStartTriggerReferences
			?? $baseline->verbatimStartTriggerReferences()
		;
		$this->baselineImmutableFingerprints = $baselineImmutableFingerprints ?? $baseline->immutableFingerprints();
		$this->baselineStaticNodeFingerprints = $baselineStaticNodeFingerprints
			?? $baseline->staticConsumerFingerprints()
		;
		$this->baselineStaticNodeReferences = $baselineStaticNodeReferences ?? $baseline->staticConsumerReferences();
		$this->baselineStorageCodes = $baselineStorageCodes ?? $baseline->storageCodes();
		$this->baselineCreatorStorageCodes = $baselineCreatorStorageCodes ?? $baseline->creatorStorageCodes();
	}

	/**
	 * Whether this very block already carries this very value for this setting - in the published version, or in
	 * the graph the agent was HANDED ({@see self::$handed}). The two are read as one here for the same reason the
	 * option check reads its two maps as one ({@see self::hasCarriedOptionValue()}): the agent has to be able to
	 * send back the graph it was given, and a draft block has no published twin to be matched against.
	 *
	 * It authorises no payload of its own - the restricted-setting barrier (ALG-04), the storage baselines and
	 * the block-type baseline stay on the published version alone - what it answers is "this value is not
	 * something the agent authored here".
	 */
	public function carriesBlockValue(
		?string $blockId,
		?string $blockType,
		string $settingName,
		mixed $value,
	): bool
	{
		return $this->baseline->carriedValueAllowed($blockId, $blockType, $settingName, $value)
			|| $this->handed->carriedValueAllowed($blockId, $blockType, $settingName, $value)
		;
	}

	/**
	 * Whether this very identity - this id on a block of this very type - is one the server wrote rather than one
	 * the agent invented, taken from the published version or from the graph the agent was handed. Deliberately
	 * narrower than "the name exists somewhere": moving a name between blocks would let the agent dress a new
	 * block in an old one (ALG-02, BRIEF § 4.3).
	 */
	public function carriesBlockIdentity(?string $blockId, ?string $blockType): bool
	{
		return $this->baseline->match($blockId, $blockType) !== null
			|| $this->handed->match($blockId, $blockType) !== null
		;
	}

	/**
	 * Whether this very block stands in the graph the server wrote - the published version or the one the agent was
	 * HANDED - unchanged over its WHOLE compared settings set (ALG-02 `requirementExemptAllowed`). The criterion the
	 * requiredness exemption rests on ({@see AgentBlockSettingsValidator}), and the third question put to the handed
	 * graph, kept here beside the two others so all three answer by one rule.
	 *
	 * The handed graph counts for the reason it counts in the two above: `template.get` answers with the DRAFT
	 * whenever the template has one, so a block a person left unfinished in the editor is a block the agent is given
	 * and could not give back - the round-trip of the state the server itself handed over, not of a payload the
	 * agent authored.
	 *
	 * It opens no hole, because the agent cannot put such a block into the draft: a block of its own with an
	 * unfilled required setting is refused by this very check, so an exempt one reaches the draft only from the
	 * editor, that is from a person entitled to save an unfinished draft. And it is no licence to copy the exemption
	 * elsewhere: the match is by id AND type, and one differing value over the compared set takes the exemption from
	 * the whole block (AC-36).
	 *
	 * @param array<string, mixed> $submittedValues values as sent, keyed by the lower-cased setting name
	 * @param list<string> $comparedNames names that take part in the comparison, in any case
	 */
	public function carriesBlockUnchanged(
		?string $blockId,
		?string $blockType,
		array $submittedValues,
		array $comparedNames,
	): bool
	{
		return $this->baseline->requirementExemptAllowed($blockId, $blockType, $submittedValues, $comparedNames)
			|| $this->handed->requirementExemptAllowed($blockId, $blockType, $submittedValues, $comparedNames)
		;
	}

	/**
	 * Whether the graph the server wrote - the published version or the one the agent was HANDED - already carries
	 * this restricted passthrough setting with this very value (ALG-04). The value half of the barrier, asked by the
	 * top-level check ({@see AgentBlockValidator::authorizeRestrictedSettings()}) and by the nested one
	 * ({@see AgentComplexRulesValidator::authorizeRestrictedSettings()}) through one account of the key, so the two
	 * cannot drift apart from each other or from the collection in
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory}.
	 *
	 * The handed graph counts for the reason {@see self::$handedRestrictedSettingFingerprints} names, and it widens
	 * nothing the agent could have written itself: this barrier stands on every write path of the contour, so a
	 * restricted value reaches the draft as the published one or by the hand of a person.
	 */
	public function carriesRestrictedSettingValue(string $name, mixed $value): bool
	{
		$fingerprint = RestrictedSettingRegistry::fingerprintOf($name, $value);

		return isset($this->baselineRestrictedSettingFingerprints[$fingerprint])
			|| isset($this->handedRestrictedSettingFingerprints[$fingerprint])
		;
	}

	/**
	 * Whether that value stands at that very location in the graph the server wrote - the location half of ALG-04,
	 * which keeps an authorised value from being copied onto another node or duplicated across the graph
	 * ({@see AgentBlocksValidator::validateRestrictedSettingReferences()}).
	 */
	public function carriesRestrictedSettingReference(
		string $location,
		string $type,
		string $name,
		mixed $value,
	): bool
	{
		$fingerprint = RestrictedSettingRegistry::referenceFingerprint($location, $type, $name, $value);

		return isset($this->baselineRestrictedSettingReferences[$fingerprint])
			|| isset($this->handedRestrictedSettingReferences[$fingerprint])
		;
	}

	/**
	 * Whether the location half has any location to admit at all. Asked before the restricted names of a block type
	 * are resolved, because resolving them includes activity files: a graph whose server-written state carries no
	 * restricted setting has nothing for that half to judge, and the value half answers such a graph on its own.
	 */
	public function hasRestrictedSettingReferences(): bool
	{
		return $this->baselineRestrictedSettingReferences !== []
			|| $this->handedRestrictedSettingReferences !== []
		;
	}

	/**
	 * @param list<string> $codes
	 */
	public function withOwnedStorageCodes(array $codes): self
	{
		return new self(
			documentType: $this->documentType,
			templateId: $this->templateId,
			source: $this->source,
			userId: $this->userId,
			isUserAdmin: $this->isUserAdmin,
			baselineImmutableFingerprints: $this->baselineImmutableFingerprints,
			baselineStaticNodeFingerprints: $this->baselineStaticNodeFingerprints,
			baselineStorageCodes: $this->baselineStorageCodes,
			baselineRestrictedSettingFingerprints: $this->baselineRestrictedSettingFingerprints,
			baselineCreatorStorageCodes: $this->baselineCreatorStorageCodes,
			ownedStorageCodes: $codes,
			baselineConstants: $this->baselineConstants,
			baselineWizardCodes: $this->baselineWizardCodes,
			draftWizardCodes: $this->draftWizardCodes,
			hasPublishedBaseline: $this->hasPublishedBaseline,
			baselineBlockTypes: $this->baselineBlockTypes,
			baselineVerbatimStartTriggerReferences: $this->baselineVerbatimStartTriggerReferences,
			baselineStaticNodeReferences: $this->baselineStaticNodeReferences,
			baselineRestrictedSettingReferences: $this->baselineRestrictedSettingReferences,
			baselineOptionValues: $this->baselineOptionValues,
			draftOptionValues: $this->draftOptionValues,
			baseline: $this->baseline,
			baselinePublishedNames: $this->baselinePublishedNames,
			handed: $this->handed,
			handedRestrictedSettingFingerprints: $this->handedRestrictedSettingFingerprints,
			handedRestrictedSettingReferences: $this->handedRestrictedSettingReferences,
		);
	}

	/**
	 * Whether the template the agent was handed already carries this value for this setting of this block type,
	 * under this preset and this document type - the published version or the bound draft alike
	 * ({@see self::$draftOptionValues} for why the draft counts here and nowhere else).
	 *
	 * The single account of the key, so the collection in
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory} and the
	 * lookup in {@see AgentSettingValueValidator} cannot drift apart.
	 *
	 * @param array|null $documentType the document type the option list was built for; null for a level built for
	 *        the document type of the request itself ({@see self::optionValueFingerprint()})
	 */
	public function hasCarriedOptionValue(
		string $blockType,
		?string $presetId,
		string $settingName,
		string $value,
		?array $documentType = null,
	): bool
	{
		$fingerprint = self::optionValueFingerprint($blockType, $presetId, $settingName, $value, $documentType);

		return isset($this->baselineOptionValues[$fingerprint])
			|| isset($this->draftOptionValues[$fingerprint])
		;
	}

	/**
	 * The name a child of a map setting is carried under, so the collection of the baseline and the lookup of the
	 * check spell it the same way. The parent name and the key inside it, and never the position in a list: an
	 * agent that reorders the elements of a multiple map has changed nothing about the value of any of them.
	 *
	 * The separator needs no escaping: both halves are setting names, matched case-insensitively, and the whole
	 * result is a name part of {@see self::optionValueFingerprint()}, which writes its length in front.
	 */
	public static function childSettingName(string $settingName, string $childName): string
	{
		return $settingName . '.' . $childName;
	}

	/**
	 * The key never leaves the request - it is an array key of {@see self::$baselineOptionValues} and nothing
	 * else - so it has to separate tuples, not resist anyone, and a hash over a serialize costs both on every
	 * element of every published value. Each part is written with its own length in front, so the key of one
	 * tuple cannot spell the key of another whatever bytes a block type, a preset, a setting name or a value hold.
	 *
	 * The block type and the setting name are matched without regard to case, because both are resolved that way.
	 * The preset rides verbatim, because it is not: the catalog holds it under an exact key
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockMetadataResolver::hasPreset()},
	 * {@see \Bitrix\Bizproc\Activity\ActivityDescription::getPresetById()}), so a case variant is a different
	 * preset - and a block carrying one is refused before this check ever runs. No preset and the empty preset are
	 * the same key, exactly as they are the same (empty) option list.
	 *
	 * $documentType is the third half of what the list is built from, and it is not the same answer at every level.
	 * A block of the graph is described for the document type of the edited template, one and the same for every
	 * block of the request, so it names none and the part carries {@see self::OWN_DOCUMENT_TYPE_MARK}: the whole map
	 * belongs to one request and one template. The sub-step of a complex node is described for the EFFECTIVE
	 * document type of its host node instead - the type the host class fixes, the template's when it fixes none
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ComplexBlockDetailFactory::describeSubAction()},
	 * {@see \Bitrix\BizprocDesigner\Internal\Service\Activity\NodeFilterAvailability::resolveEffectiveDocumentType()}) -
	 * and one template may well hold complex nodes of two different fixed types, the same sub-step under both. So
	 * that level names its type, and the deal variant of a sub-step unlocks nothing in the lead one.
	 *
	 * The value is the one part that has no length written for it, because it is last and nothing follows it -
	 * and that is what let a long text be carried whole: the map then cost a second copy of every setting of the
	 * published template, megabytes rather than kilobytes where those settings are texts. Past
	 * {@see self::MAX_INLINE_VALUE_LENGTH} the value rides as a digest instead ({@see self::valueTail()}).
	 */
	public static function optionValueFingerprint(
		string $blockType,
		?string $presetId,
		string $settingName,
		string $value,
		?array $documentType = null,
	): string
	{
		$type = mb_strtolower($blockType);
		$preset = $presetId ?? '';
		$name = mb_strtolower($settingName);
		$document = self::documentTypePart($documentType);

		return strlen($type) . ':' . $type
			. '|' . strlen($preset) . ':' . $preset
			. '|' . strlen($document) . ':' . $document
			. '|' . strlen($name) . ':' . $name
			. '|' . self::valueTail($value)
		;
	}

	/**
	 * The document-type part, marked by which of the two answers it is. A named type is written element by element
	 * with the length of each in front, so a type of two elements cannot spell one of three however its elements
	 * are cut, and it rides verbatim: a document type is matched exactly everywhere else it is used
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\RestrictedSettingRegistry::namesFor()},
	 * {@see \CBPRuntime::searchActivitiesByType()}).
	 */
	private static function documentTypePart(?array $documentType): string
	{
		if ($documentType === null)
		{
			return self::OWN_DOCUMENT_TYPE_MARK;
		}

		$part = self::NAMED_DOCUMENT_TYPE_MARK;
		foreach ($documentType as $element)
		{
			$element = is_scalar($element) ? (string)$element : '';
			$part .= strlen($element) . ':' . $element . ',';
		}

		return $part;
	}

	/**
	 * The tail of the key, marked by which of the two forms it is in. The marks are what keeps the two forms
	 * apart: every digested tail starts with one byte, every carried one with the other, so no value can be
	 * written in a way that spells the tail of a different value - a value shaped like a digest is short and
	 * therefore carried, mark and all.
	 *
	 * A crafted digest collision is not guarded against, and does not need to be: the option list is a help to
	 * the agent rather than a barrier, and the same value passes the check anyway on a block sent without a
	 * presetId, where the list is empty.
	 */
	private static function valueTail(string $value): string
	{
		return strlen($value) > self::MAX_INLINE_VALUE_LENGTH
			? self::DIGESTED_VALUE_MARK . md5($value)
			: self::INLINE_VALUE_MARK . $value
		;
	}
}

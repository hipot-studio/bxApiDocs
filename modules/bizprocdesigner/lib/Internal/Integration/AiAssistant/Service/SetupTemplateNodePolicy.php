<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupBlocksParseResult;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupConstantDeclaration;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SetupTemplateNodeMatcher;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Admits a setup wizard node whose fingerprint differs from the published template baseline. Until this phase such
 * a node was refused unread, which left an external agent unable to configure the wizard at all, while its owner
 * edits it freely in the designer.
 *
 * The rule order is normative:
 *   1. any other node type is refused unread. Defensive duplication: the reverse pipeline judges the type before it
 *      asks ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator}), so
 *      this rule answers a direct caller alone and keeps the policy fail-closed on its own contract;
 *   2. the state of the template is judged before the form: no context, no published baseline to compare against
 *      and an unreadable baseline wizard form are refusals of their own, each naming the state of the TEMPLATE
 *      rather than the edit, or the agent would look for the fault in the form it has just sent;
 *   3. the item bound of one form comes next, ahead of every reading: it is counted off the raw form
 *      ({@see self::countFormItems()}), so a form over it is turned down without being parsed at all - the
 *      parse is the very cost the bound exists to keep an entry from paying;
 *   4. the reading entries of the process engine must be there: without them neither the form nor the type list of
 *      this document type is known. Read fail-closed - no reading, no admission - unlike the neighbouring
 *      consumers of the node ({@see \Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService}), which
 *      answer with an empty result: an empty result here would admit whatever it failed to read;
 *   5. every constant item of the form is judged by ALG-01, inherited and new on separate branches; the other
 *      technical ceiling - the declaration bound - is against growth and reaches the new-code branch alone.
 *
 * The policy is resource-based, never subject-based: the subject is authorised once per entry by
 * {@see OperationAuthorizationGuard}, and re-checking rights per node is forbidden by the reverse-entry contract.
 *
 * admit() is pure and holds no memo: the value it answers by is assembled outside it, so the same node judged twice
 * in one pass cannot get two verdicts. Everything spanning more than one node - the code counter of the graph and
 * the declaration ceiling - is counted by the validator BEFORE the first verdict and arrives ready-made, or the
 * verdict would depend on the order of the blocks. The form is parsed once per node for the same reason, and the
 * parse result is what admit() is handed.
 *
 * No value authored by the agent is ever interpolated into a refusal: the reasons name conditions, and the only
 * data filled in is the server's own - the place the parser counted the item or the structural error at, the error
 * code of the parse entry and the type list this document type allows.
 */
final class SetupTemplateNodePolicy
{
	/**
	 * The wording a node of any other type is refused with. Its own text rather than the one
	 * {@see CreateStorageNodePolicy::REASON_TYPE_NOT_ALLOWED} carries: the two policies judge different node
	 * families, and one text for both would send the agent correcting the wrong node.
	 */
	public const REASON_NODE_TYPE_NOT_ALLOWED =
		'setup wizard admission judges the template setup wizard node alone, and this node is of another type';

	public const REASON_NO_CONTEXT =
		'setup wizard node cannot be judged: this entry carries no template context to compare the form against';

	/**
	 * Names the state of the template, never the edit. The published version is the only baseline the barrier is
	 * allowed to trust - the draft would close the check on itself - so with nothing published there is no set an
	 * inherited constant could be recognised by, and every constant of the form would read as a new one.
	 *
	 * Whether anything is published is read off the flag of the context and never off the emptiness of its ownership
	 * sets: a published template that has declared no constant yet leaves both of them empty and is a baseline all
	 * the same, so judging it by them would refuse the very edit this policy exists for - adding a wizard to a
	 * template that had none.
	 */
	public const REASON_NO_PUBLISHED_BASELINE =
		'setup wizard node cannot be judged: this template has no published version at all, so a constant inherited '
		. 'from it cannot be told apart from one the form declares anew';

	public const REASON_BASELINE_WIZARD_UNREADABLE =
		'setup wizard node cannot be judged: the setup wizard settings of the published template cannot be read, '
		. 'so a constant inherited from it cannot be told apart from one the form declares anew';

	/**
	 * The version-mismatch refusal (readiness criterion 12): the barrier and the wizard node live in two modules
	 * released independently, and the portal may carry a process engine that publishes neither reading yet.
	 */
	public const REASON_ENGINE_ENTRY_UNAVAILABLE =
		'setup wizard node cannot be judged: the process engine of this portal publishes no reading of the wizard '
		. 'form and of the constant types it allows';

	public const REASON_FORM_UNREADABLE =
		'setup wizard form does not parse: every form block must carry a non-empty item list, and every item a '
		. 'known item type with the fields that type requires';

	// The two technical ceilings of the reverse entry. The numbers belong to the barrier that applies them, and
	// each refusal names its own, because a bound the agent cannot read is one it cannot correct against.

	public const REASON_FORM_TOO_LARGE =
		'setup wizard form of one node may carry at most ' . AgentBlocksValidator::MAX_SETUP_FORM_ITEMS
		. ' items of every kind taken together - constants, headings, descriptions and delimiters alike - and '
		. 'this form carries more';

	/**
	 * Bites growth alone: an edit of the constants the published wizard already declares passes at the ceiling
	 * too, so a template that outgrew the bound before it existed stays editable and needs no migration.
	 */
	public const REASON_DECLARATION_LIMIT =
		'this template declares as many setup wizard constants as a reverse entry accepts ('
		. AgentBlocksValidator::MAX_SETUP_DECLARATIONS . '), so no constant of a code the published wizard does '
		. 'not declare may be added; editing the constants it already declares stays open';

	/**
	 * The narrow ownership set and the constants of the published template disagree about one code. Named apart
	 * from the mismatch rules below: the disagreement is a state of the template, and the form that repeats such a
	 * code is not at fault.
	 */
	public const REASON_BASELINE_DECLARATION_MISSING =
		'setup wizard constant is declared by a wizard form of the published template while that template carries '
		. 'no constant declaration under this code, so the edit has nothing to be judged against';

	// ALG-01, the rules of one constant item: uniqueness across the graph first, then the inherited branch.

	public const REASON_DUPLICATE_CODE =
		'setup wizard constant code must be declared once across every setup wizard node of the template, and this '
		. 'code is declared more than once';

	public const REASON_TYPE_MISMATCH =
		'setup wizard constant inherited from the published template must keep the type that template declares for '
		. 'it; author a constant of a new code to introduce another type';

	public const REASON_MULTIPLE_MISMATCH =
		'setup wizard constant inherited from the published template must keep the multiplicity that template '
		. 'declares for it; author a constant of a new code to introduce another multiplicity';

	public const REASON_SETTINGS_MISMATCH =
		'setup wizard constant inherited from the published template must keep the field-type settings that '
		. 'template declares for it: every setting the form carries must be declared there under the same value';

	public const REASON_OPTION_REMOVED =
		'setup wizard constant inherited from the published template may add and rename its choice options but may '
		. 'not drop one: every option key the published declaration carries must stay in the form';

	// The branch of a constant the published template does not declare through its wizard.

	public const REASON_CODE_TAKEN =
		'setup wizard constant code is already declared by this template outside its setup wizard, and the wizard '
		. 'may not redefine what it does not own; author the constant under a code of its own';

	public const REASON_CODE_FORM =
		'a setup wizard constant the published template does not declare must carry the code the wizard editor '
		. 'produces: the prefix SetupTemplateActivity_ followed by exactly 10 latin letters or digits';

	public const REASON_TYPE_NOT_ALLOWED =
		'a setup wizard constant the published template does not declare must be of a type the wizard supports for '
		. 'this document type';

	public const REASON_SETTINGS_NOT_ALLOWED =
		'a setup wizard constant the published template does not declare may carry no field-type settings other '
		. 'than selectorId, the identifier of an entity selector';

	private const LOGGER_ID = 'bizprocdesigner.aiassistant.setup_template_policy';

	/** Longest agent-authored value a diagnostic record keeps: a block id is bounded by nothing. */
	private const LOG_VALUE_MAX_LENGTH = 128;

	/** Activity code of the wizard node, as {@see \CBPRuntime::includeActivityFile()} takes it. */
	private const SETUP_ACTIVITY_CODE = 'SetupTemplateActivity';

	private const PARSE_METHOD = 'parseSetupBlocks';
	private const ALLOWED_TYPES_METHOD = 'getAllowedConstantTypes';

	/** The node setting the wizard form is persisted in, and the key one of its blocks keeps its items under. */
	private const FORM_SETTING = 'blocks';
	private const FORM_ITEMS_KEY = 'items';

	/** Code form the wizard editor produces (bizproc setup-template-activity: constants.js, utils.js). */
	private const EDITOR_CODE_PATTERN = '/^SetupTemplateActivity_[A-Za-z0-9]{10}$/D';

	/**
	 * Field-type settings a constant the published template does not declare may carry. The entity selector is the
	 * one field type configured by a setting, and its identifier is validated against the selector registry by the
	 * wizard node itself, so the value needs no rule of its own here.
	 */
	private const ALLOWED_NEW_CONSTANT_SETTINGS = ['selectorId'];

	// Keys of one published constant declaration, as the wizard editor writes them (utils.js: convertConstants).
	private const DECLARATION_TYPE = 'Type';
	private const DECLARATION_MULTIPLE = 'Multiple';
	private const DECLARATION_OPTIONS = 'Options';
	private const DECLARATION_SETTINGS = 'Settings';

	private ?LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	/**
	 * @param SetupBlocksParseResult|null $parsedForm form of THIS node, parsed once per pass by
	 *        {@see self::parseNodeForm()}; null means the engine publishes no parse entry
	 * @param list<string>|null $allowedConstantTypes type codes the wizard allows for the document type of the
	 *        graph, read once per pass by {@see self::readAllowedConstantTypes()}; null means no entry to read them
	 * @param array<string, int> $graphCodeCounts how many times each constant code occurs across every wizard node
	 *        of the graph, counted by the validator BEFORE the first verdict (ALG-01 `graphCodeCounts`). The set
	 *        produced by the graph answers for uniqueness alone and never for inheritance, or two declarations of
	 *        one graph would legitimise each other
	 * @param bool $formItemLimitExceeded whether this form is over {@see AgentBlocksValidator::MAX_SETUP_FORM_ITEMS},
	 *        counted by the validator off the raw form before it was parsed - a form over the bound reaches this
	 *        method with no parse result at all
	 * @param bool $declarationLimitExceeded whether the template as a whole is over
	 *        {@see AgentBlocksValidator::MAX_SETUP_DECLARATIONS}. Arrives ready-made for the same reason the code
	 *        counter does: it spans every wizard node of the graph and the narrow ownership set of the published
	 *        version, so counting it while the nodes are judged would tie the verdict to their order
	 */
	public function admit(
		string $type,
		?SetupBlocksParseResult $parsedForm,
		?array $allowedConstantTypes,
		array $graphCodeCounts,
		bool $formItemLimitExceeded,
		bool $declarationLimitExceeded,
		?ValidationContext $context,
	): AuthorizationVerdict
	{
		if (!SetupTemplateNodeMatcher::matches($type))
		{
			return AuthorizationVerdict::reject(self::REASON_NODE_TYPE_NOT_ALLOWED);
		}

		if ($context === null)
		{
			return AuthorizationVerdict::reject(self::REASON_NO_CONTEXT);
		}

		if ($context->baselineWizardCodes === null)
		{
			return AuthorizationVerdict::reject(self::REASON_BASELINE_WIZARD_UNREADABLE);
		}

		if (!$context->hasPublishedBaseline)
		{
			return AuthorizationVerdict::reject(self::REASON_NO_PUBLISHED_BASELINE);
		}

		// Before the readings and before the parse they would feed: the bound exists to keep the form of an entry
		// from being read at all, so a form over it is turned down whatever the parse would have said about it.
		if ($formItemLimitExceeded)
		{
			return AuthorizationVerdict::reject(self::REASON_FORM_TOO_LARGE);
		}

		if ($parsedForm === null || $allowedConstantTypes === null)
		{
			return AuthorizationVerdict::reject(self::REASON_ENGINE_ENTRY_UNAVAILABLE);
		}

		if (!$parsedForm->isSuccess())
		{
			return AuthorizationVerdict::reject(self::describeParseFailure($parsedForm));
		}

		foreach ($parsedForm->declarations as $declaration)
		{
			$verdict = self::admitConstant(
				$declaration,
				$allowedConstantTypes,
				$graphCodeCounts,
				$declarationLimitExceeded,
				$context->baselineWizardCodes,
				$context,
			);
			if (!$verdict->allowed)
			{
				return $verdict;
			}
		}

		return AuthorizationVerdict::allow();
	}

	/**
	 * ALG-01 for one constant item. The two branches are kept apart on purpose: an inherited constant has a
	 * declaration to be judged against, while a new one is declared by the form itself and answers to rules of its
	 * own.
	 *
	 * Inheritance and occupancy are judged against what came from the published template and never against what the
	 * graph under validation produced, or two declarations of one graph would legitimise each other. What the graph
	 * produced answers for uniqueness alone, and it arrives counted.
	 *
	 * @param list<string> $allowedConstantTypes
	 * @param array<string, int> $graphCodeCounts
	 * @param list<string> $baselineWizardCodes the narrow set, taken apart from the context so that its "could not
	 *        be read" state cannot reach the rules as an empty one - that state is a refusal of its own
	 */
	private static function admitConstant(
		SetupConstantDeclaration $item,
		array $allowedConstantTypes,
		array $graphCodeCounts,
		bool $declarationLimitExceeded,
		array $baselineWizardCodes,
		ValidationContext $context,
	): AuthorizationVerdict
	{
		if (($graphCodeCounts[$item->code] ?? 0) > 1)
		{
			return self::rejectAt(self::REASON_DUPLICATE_CODE, $item);
		}

		if (in_array($item->code, $baselineWizardCodes, true))
		{
			return self::admitInheritedConstant($item, $context);
		}

		// Past the inherited branch on purpose: the ceiling is against growth, so what it turns down is the code
		// the published wizard does not declare, whatever else that code would have answered to.
		if ($declarationLimitExceeded)
		{
			return self::rejectAt(self::REASON_DECLARATION_LIMIT, $item);
		}

		if (array_key_exists($item->code, $context->baselineConstants))
		{
			return self::rejectAt(self::REASON_CODE_TAKEN, $item);
		}

		if (!self::isEditorAuthoredCode($item->code))
		{
			return self::rejectAt(self::REASON_CODE_FORM, $item);
		}

		if (!in_array($item->type, $allowedConstantTypes, true))
		{
			// The list is the server's own, so naming it carries nothing the agent authored back to the agent.
			return self::rejectAt(
				self::REASON_TYPE_NOT_ALLOWED,
				$item,
				'allowed types: ' . ($allowedConstantTypes === [] ? 'none' : implode(', ', $allowedConstantTypes)),
			);
		}

		foreach (array_keys($item->settings) as $name)
		{
			if (!in_array((string)$name, self::ALLOWED_NEW_CONSTANT_SETTINGS, true))
			{
				return self::rejectAt(self::REASON_SETTINGS_NOT_ALLOWED, $item);
			}
		}

		return AuthorizationVerdict::allow();
	}

	/**
	 * A constant the wizard of the published template already declares. Type, multiplicity and the field-type
	 * settings must stay as declared; names, descriptions and the order of the items are free.
	 *
	 * Requiredness is out of the comparison: the form always produces `Required: 0`, so comparing it would refuse
	 * every form of a template whose owner marked a constant required.
	 *
	 * Choice options are compared by KEY and never by pair: renaming an option is a legitimate edit, so comparing
	 * the labels would refuse it, while dropping a key takes away a value already collected under it.
	 *
	 * Settings are compared over the keys the form carries: a declaration may be richer than a form (its owner
	 * completed it in the designer), and requiring the whole of it to match would refuse an edit with no way to fix
	 * it. A key the form carries and the declaration does not know at all is a mismatch all the same - there is
	 * nothing to compare it against, and passing it over would accept a setting its owner never declared.
	 */
	private static function admitInheritedConstant(
		SetupConstantDeclaration $item,
		ValidationContext $context,
	): AuthorizationVerdict
	{
		$declared = $context->baselineConstants[$item->code] ?? null;
		if (!is_array($declared))
		{
			return self::rejectAt(self::REASON_BASELINE_DECLARATION_MISSING, $item);
		}

		if ($item->type !== (string)($declared[self::DECLARATION_TYPE] ?? ''))
		{
			return self::rejectAt(self::REASON_TYPE_MISMATCH, $item);
		}

		if ($item->multiple !== \CBPHelper::getBool($declared[self::DECLARATION_MULTIPLE] ?? false))
		{
			return self::rejectAt(self::REASON_MULTIPLE_MISMATCH, $item);
		}

		$declaredSettings = $declared[self::DECLARATION_SETTINGS] ?? [];
		$declaredSettings = is_array($declaredSettings) ? $declaredSettings : [];
		foreach ($item->settings as $name => $value)
		{
			// Loose comparison on purpose: both sides travel through JSON and a serialized template, so an
			// unchanged setting must not be refused over the type or the key order its transport left it in.
			if (!array_key_exists($name, $declaredSettings) || $declaredSettings[$name] != $value)
			{
				return self::rejectAt(self::REASON_SETTINGS_MISMATCH, $item);
			}
		}

		$declaredOptions = $declared[self::DECLARATION_OPTIONS] ?? [];
		$declaredOptions = is_array($declaredOptions) ? $declaredOptions : [];
		foreach (array_keys($declaredOptions) as $option)
		{
			if (!array_key_exists($option, $item->options))
			{
				return self::rejectAt(self::REASON_OPTION_REMOVED, $item);
			}
		}

		return AuthorizationVerdict::allow();
	}

	/**
	 * The refusal names the item by the position the parser counted it at - the same position the wizard owner sees
	 * in the settings panel - because no value the agent authored may travel back inside a refusal, the code
	 * included. The detail is server-side data alone.
	 */
	private static function rejectAt(
		string $reason,
		SetupConstantDeclaration $item,
		string $detail = '',
	): AuthorizationVerdict
	{
		return AuthorizationVerdict::reject(
			$reason
			. ($detail === '' ? '' : '; ' . $detail)
			. self::formatPosition($item->blockPosition, $item->itemPosition)
		);
	}

	/**
	 * The refusal for a form that does not parse names the FIRST structural error the parse entry reported - its
	 * code and the place it was found at - the same way an item refusal names its item. Without them the agent is
	 * told its form is broken and left with no place to correct, while the entry has already counted both.
	 *
	 * The code is one of the fixed values of the parse entry and the positions are the ones it counted, so this
	 * carries nothing the agent authored back to the agent, exactly as {@see self::rejectAt()} does not.
	 */
	private static function describeParseFailure(SetupBlocksParseResult $parsedForm): string
	{
		$error = $parsedForm->errors[0] ?? null;
		if ($error === null)
		{
			return self::REASON_FORM_UNREADABLE;
		}

		return self::REASON_FORM_UNREADABLE
			. '; first structural error: ' . $error->code
			. self::formatPosition($error->blockPosition, $error->itemPosition)
		;
	}

	/**
	 * The place inside the form a refusal is about, as the parser counted it - 1-based and the same the wizard owner
	 * sees in the settings panel. Either position may be absent: a parse error about the form as a whole names no
	 * place at all, and one about a block names no item.
	 */
	private static function formatPosition(?int $blockPosition, ?int $itemPosition): string
	{
		$place = [];
		if ($blockPosition !== null)
		{
			$place[] = 'block ' . $blockPosition;
		}

		if ($itemPosition !== null)
		{
			$place[] = 'item ' . $itemPosition;
		}

		return $place === [] ? '' : ' (setup wizard form ' . implode(', ', $place) . ')';
	}

	/**
	 * Records a refusal in the module diagnostic channel, so every reverse entry keeps the refusal observable.
	 * Deliberately outside admit(): the verdict is a pure function of what it is handed, and a write inside it
	 * would tie the record to how many times the verdict happened to be asked for. The record names the template
	 * and the entry, which the caller does not pass on: the agent gets the reason alone.
	 */
	public function reportRejection(
		string $blockId,
		AuthorizationVerdict $verdict,
		ValidationContext $context,
	): void
	{
		$this->getLogger()->warning(
			'AI reverse entry: setup wizard node refused',
			[
				'templateId' => $context->templateId,
				'source' => $context->source->value,
				'blockId' => self::forLog($blockId),
				'reason' => $verdict->reason,
			],
		);
	}

	/**
	 * Parses the wizard form of one node through the public entry of the wizard node itself, so the barrier and the
	 * owner of the form never disagree about what a form declares. Called once per node by the validator pass, and
	 * the result is what the verdict is then judged on: parsing it again would pay the selector registry twice per
	 * node, which is the very cost the item limit exists to bound.
	 *
	 * Takes the raw form the node persists ({@see self::readRawForm()}) rather than the settings of the node, so
	 * that one node costs one reading of its settings: the item bound is counted off the very same raw form.
	 *
	 * Returns null - and never a parse failure - when the engine publishes no such entry: a version mismatch is a
	 * state of the portal, told apart from a form that does not parse. A payload that is not the form at all is left
	 * to the entry to report, so its own error codes stay the single account of what a broken form is.
	 *
	 * @param array $rawForm form of THIS node as {@see self::readRawForm()} read it
	 */
	public static function parseNodeForm(array $rawForm): ?SetupBlocksParseResult
	{
		try
		{
			if (!\CBPRuntime::getRuntime()->includeActivityFile(self::SETUP_ACTIVITY_CODE))
			{
				return null;
			}

			$parsed = \CBPActivity::callStaticMethod(
				self::SETUP_ACTIVITY_CODE,
				self::PARSE_METHOD,
				[$rawForm],
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		return $parsed instanceof SetupBlocksParseResult ? $parsed : null;
	}

	/**
	 * Type codes a wizard constant may use for this document type, read from the wizard node - the list the
	 * settings panel of the wizard is built from, i.e. what the node can actually render. The menu of the editor
	 * offers a subset of it; resting the rule on the menu would make a server rule hostage to an interface change.
	 *
	 * @return list<string>|null null when the engine publishes no such entry
	 */
	public static function readAllowedConstantTypes(DocumentDescription $documentType): ?array
	{
		try
		{
			if (!\CBPRuntime::getRuntime()->includeActivityFile(self::SETUP_ACTIVITY_CODE))
			{
				return null;
			}

			$types = \CBPActivity::callStaticMethod(
				self::SETUP_ACTIVITY_CODE,
				self::ALLOWED_TYPES_METHOD,
				[$documentType->toBizprocComplexType()],
			);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($types))
		{
			return null;
		}

		$codes = [];
		foreach ($types as $code)
		{
			if (is_string($code) && $code !== '')
			{
				$codes[] = $code;
			}
		}

		return array_values(array_unique($codes));
	}

	/**
	 * Whether a code is one the wizard editor authored. It is the form a constant the published template does not
	 * declare must carry ({@see self::REASON_CODE_FORM}), and the same mark tells apart the constants a draft
	 * accumulated through the wizard from the ones its owner declared elsewhere in the template.
	 */
	public static function isEditorAuthoredCode(string $code): bool
	{
		return preg_match(self::EDITOR_CODE_PATTERN, $code) === 1;
	}

	/**
	 * Items the raw form carries, counted without parsing it, so the ceiling that bounds the cost of the parse is
	 * applied before that cost is paid. Counting alone needs no item to be read: a form over the bound is turned
	 * down whole whatever its items say.
	 *
	 * A block the count cannot read contributes nothing - such a form does not parse either, and the refusal for
	 * it belongs to the parse entry.
	 *
	 * @param array $rawForm form of THIS node as {@see self::readRawForm()} read it
	 */
	public static function countFormItems(array $rawForm): int
	{
		$itemCount = 0;
		foreach ($rawForm as $formBlock)
		{
			$items = is_array($formBlock) ? ($formBlock[self::FORM_ITEMS_KEY] ?? null) : null;
			if (is_array($items))
			{
				$itemCount += count($items);
			}
		}

		return $itemCount;
	}

	/**
	 * The form as the node persists it. A payload transported as a JSON string is decoded exactly as the baseline
	 * collection decodes it ({@see ValidationContextFactory}), and anything that is no form at all becomes the
	 * empty payload the parse entry reports as a structural error of its own.
	 *
	 * Read once per node and handed on to {@see self::countFormItems()} and {@see self::parseNodeForm()} alike:
	 * both are about the same form, and reading the settings of a node twice would decode its payload twice.
	 */
	public static function readRawForm(AgentSettingCollection $settings): array
	{
		$value = null;
		foreach ($settings as $setting)
		{
			if (strcasecmp($setting->name, self::FORM_SETTING) === 0)
			{
				$value = $setting->value;
			}
		}

		if (is_string($value))
		{
			try
			{
				$value = Json::decode($value);
			}
			catch (ArgumentException)
			{
				return [];
			}
		}

		return is_array($value) ? $value : [];
	}

	/**
	 * The block id is authored by the agent and bounded by nothing, while one entry carries up to 256 blocks:
	 * written as it came, a refusal with newlines would forge records of its own inside the channel and a
	 * kilobyte-long id would push the real records out of it. Control characters collapse into a single space and
	 * the value is cut to a length that still names the node.
	 *
	 * A null is PCRE giving up, not a cleaned value: casting it to a string would leave the refusal naming no
	 * node at all, so the id is logged as it stands - a record that reads awkwardly still beats a blank one.
	 */
	private static function forLog(string $value): string
	{
		$singleLine = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? $value;

		return mb_strlen($singleLine) > self::LOG_VALUE_MAX_LENGTH
			? mb_substr($singleLine, 0, self::LOG_VALUE_MAX_LENGTH) . '...'
			: $singleLine;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}

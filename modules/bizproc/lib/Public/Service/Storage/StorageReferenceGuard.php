<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Storage;

use Bitrix\Bizproc\Public\Provider\DataViewProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;

/**
 * Authorises a storage reference of a read/write/delete consumer node against the published template baseline
 * (ALG-02). The rule order is normative:
 *   1. a StorageId that addresses storage at all is refused: the runtime resolver prefers such an id over the
 *      code and never checks ownership - an IDOR vector. "No id" is a whitelist (null, 0, '0', blank), because
 *      the runtime casts the property to int and '123suffix' becomes 123 just as '123' does. An unchanged
 *      baseline node is the sole exception and falls through to rule 4, or a published template addressing
 *      storage by id would lose idempotency;
 *   2. editing a result data-view is refused BEFORE the token branch, or a data-view addressed by a
 *      per-template token would slip past the edit ban; reading one is legal;
 *   3. a PURE per-template token code is editable, but only when the published baseline declares that code:
 *      the runtime expands the token inside an arbitrary prefix/suffix, so 'DAY_PLANNER_{token}1' would reach
 *      the storage of a neighbouring launched copy. A token nested in a computable expression is not pure (it
 *      expands to a foreign code) and is left to rules 4/5;
 *   3a. a code the template itself creates is editable in any form: the criterion here is the ORIGIN of the
 *      code, not its shape - the shape was already judged by the create-node admission policy, which is the
 *      only way a code enters the owned set. The rule sits after rules 1-2 so ownership never bypasses the
 *      id-addressing ban nor the data-view edit ban, and it reads its own context field: baselineStorageCodes
 *      also holds the token codes of published consumers, whose origin is unproven (see the context docblock);
 *   4. a static code is allowed only as an immutable passthrough of a consumer already in the baseline;
 *   5. any other static code is refused - a forged consumer pointed at shared storage.
 *
 * Without a bound template (templateId <= 0) no baseline exists, so no storage consumer is authorised at all:
 * the guard is a public service and its contract must stay fail-closed even where the current entries cannot
 * reach that state.
 *
 * These rules judge the code FORM by isPureTokenCode, while the admission policy of a create node judges it by
 * the stricter isStrictTemplateBoundCode. The two criteria differ on purpose: a published consumer whose code
 * carries a suffix after the token ('DAY_PLANNER_{token}_ITEMS') passes rule 3 today and, once edited, would
 * lose the passthrough of rule 4 as well - tightening rule 3 to the strict form would take both allowing
 * branches away at once and break the round-trip of templates already published.
 *
 * The guard resolves storage by CODE only, through the public providers, and never reuses the runtime
 * resolveStorageId (it inherits the IDOR passthrough). createstoragenode/setuptemplateactivity are out of scope:
 * their immutability is enforced against the baseline separately (ALG-01), so a forged create carrying a token
 * code cannot slip through the token branch.
 */
final class StorageReferenceGuard
{
	private const TEMPLATE_ID_TOKEN = '{=Workflow:TemplateId}';

	/** Longest strict prefix: CODE is a varchar(64) and the expanded template id needs up to 10 digits. */
	private const MAX_PREFIX_LENGTH = 50;

	/**
	 * Exactly what PCRE '\s' accepts, plus NUL. The runtime patterns skip leading whitespace with '^\s*', and
	 * PHP's default trim() set is not the same one: it misses form feed (U+000C) and adds NUL. Keeping NUL in
	 * makes this barrier stricter than the pattern, never looser.
	 */
	private const PCRE_WHITESPACE = " \t\n\r\0\x0B\x0C";

	/**
	 * Every spelling of the per-template token the RUNTIME expands, and no other. The barrier and the executor
	 * have to read a code the same way: {@see \CBPActivity::ValueInlinePattern} matches a substitution
	 * case-insensitively and tolerates whitespace around the object, the colon and the field, after which
	 * {@see \Bitrix\Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter::getRuntimeProperty()} compares the
	 * OBJECT case-sensitively ('Workflow') while getRuntimeWorkflowField() lower-cases the FIELD. The asymmetry is
	 * reproduced rather than smoothed over: '{=Workflow:templateid}' expands to the template id and is recognised
	 * here, while '{=workflow:templateid}' resolves through something else entirely - the runtime looks for a
	 * workflow ACTIVITY of that name, and the agent authors block ids. What this pattern does NOT match is not
	 * therefore harmless; it is exactly what {@see self::isFullyInterpretableCode()} refuses.
	 */
	private const TEMPLATE_ID_TOKEN_PATTERN =
		'#\{=\s*Workflow\s*:\s*(?<field>[A-Za-z0-9_.]+)(\s*>\s*[A-Za-z0-9_:]+(\s*,\s*[A-Za-z0-9_]+)?)?\s*\}#';

	private readonly StorageTypeProvider $storageTypeProvider;
	private readonly DataViewProvider $dataViewProvider;

	/** @var array<string, int|null> resolved code => storage type id, for the lifetime of one reverse call */
	private array $storageTypeIdByCode = [];
	/** @var array<int, bool> storage type id => is a result data-view */
	private array $dataViewByStorageTypeId = [];

	public function __construct(
		?StorageTypeProvider $storageTypeProvider = null,
		?DataViewProvider $dataViewProvider = null,
	) {
		$this->storageTypeProvider = $storageTypeProvider ?? new StorageTypeProvider();
		$this->dataViewProvider = $dataViewProvider ?? new DataViewProvider();
	}

	public function authorizeReference(
		StorageReference $node,
		StorageOperationKind $operationKind,
		StorageAuthorizationContext $context,
	): StorageReferenceVerdict {
		// 1. anti-IDOR: an id addressing storage passes solely as an unchanged baseline node (rule 4).
		if (
			self::addressesStorageById($node->storageId)
			&& !isset($context->baselineStaticNodeFingerprints[$node->fingerprint])
		)
		{
			return StorageReferenceVerdict::reject(
				'a raw, non-scalar or computed StorageId is not allowed; address storage by code',
			);
		}

		$code = is_string($node->storageCode) ? $node->storageCode : '';

		// 2. a result data-view may be read but not edited. The token is expanded with the bound templateId so
		// the code resolves to the same storage type the runtime would use. A code PCRE failed to expand is refused
		// instead of resolved: the lookup would then run on the unexpanded text, find no storage, and let the ban
		// pass a data-view the runtime does reach.
		if ($operationKind->isEditing() && $code !== '')
		{
			$resolvedCode = self::replaceTemplateIdTokens($code, (string)$context->templateId);
			if ($resolvedCode === null)
			{
				return StorageReferenceVerdict::reject(
					'the storage code could not be expanded: the barrier cannot prove which storage it addresses',
				);
			}

			$storageTypeId = $this->resolveStorageTypeId($resolvedCode);
			if ($storageTypeId !== null && $this->isDataView($storageTypeId))
			{
				return StorageReferenceVerdict::reject('editing a result data-view storage is not allowed');
			}
		}

		// Fail-closed: with no bound template there is no baseline to authorise a reference against.
		if ($context->templateId <= 0)
		{
			return StorageReferenceVerdict::reject('a storage reference requires a bound workflow template');
		}

		// 3. pure per-template token code declared by the published baseline: isolated to the bound template.
		if ($this->isPureTokenCode($code) && $context->declaresStorageCode($code))
		{
			return StorageReferenceVerdict::allow();
		}

		// 3a. a code this template creates: the create node that carries it has already passed the admission
		// policy, so the code form is settled and only its origin matters here. Ownership authorises a code the
		// barrier reads whole - see isFullyInterpretableCode: an unreadable code would be matched against the
		// owned set as text while the runtime expands it into an address of its own.
		if ($context->ownsStorageCode($code))
		{
			return StorageReferenceVerdict::allow();
		}

		// 4. static code: allowed only as a byte-for-byte passthrough of a baseline consumer. Deliberately after
		// the fail-closed rules and free of them: this code was authored by a human in a published template and
		// may carry any substitution, so refusing it here would break the round-trip of working templates.
		if (isset($context->baselineStaticNodeFingerprints[$node->fingerprint]))
		{
			return StorageReferenceVerdict::allow();
		}

		// 5. the code is not authorised. A code the barrier cannot read whole gets its own refusal: it names what
		// the caller must remove, instead of sending the agent to look for a baseline entry that would never help.
		// Edge whitespace answers before the substitution rule and with a text of its own: a padded code carries
		// no substitution to look for, and a refusal naming one would send the agent hunting what is not there.
		if (self::hasEdgeWhitespace($code))
		{
			return StorageReferenceVerdict::reject(
				'a storage code must carry no leading or trailing whitespace: storage codes compare without '
				. 'padding, so a padded code addresses storage of its own',
			);
		}

		if (!self::isFullyInterpretableCode($code))
		{
			return StorageReferenceVerdict::reject(
				'a storage code may carry no substitution other than {=Workflow:TemplateId}: the barrier cannot '
				. 'prove which storage the code addresses',
			);
		}

		return StorageReferenceVerdict::reject('storage reference is not part of the published template baseline');
	}

	/**
	 * Whether a StorageId addresses storage bypassing the code. "No id" is a whitelist - null, 0, '0' and a
	 * blank string - because the runtime casts the activity property to int: '123suffix', ' 123' and a bizproc
	 * expression all resolve to a positive id the server cannot verify statically. Shared with the reverse
	 * pipeline that collects the baseline, so both sides of the invariant stay one predicate.
	 */
	public static function addressesStorageById(mixed $storageId): bool
	{
		if ($storageId === null)
		{
			return false;
		}

		if (!is_scalar($storageId))
		{
			return true;
		}

		$normalized = trim((string)$storageId);

		return $normalized !== '' && $normalized !== '0';
	}

	/**
	 * Whether a code binds the storage it names to the bound template: the per-template token terminates the
	 * code and the prefix before it is a non-empty run of latin letters, digits and underscores that does not end
	 * with a digit. Stricter than isPureTokenCode by design (see the class docblock); a form whose expansion
	 * cannot be proven statically is refused, and the refusal text tells the caller what to correct. Public and
	 * pure: the reverse pipeline of another module calls it as the create-node admission criterion, so both sides
	 * of the invariant stay one predicate.
	 *
	 * The prefix is judged by an ASCII allow-list rather than by a list of banned characters, because this
	 * predicate and the storage it protects count characters in different systems: PHP compares bytes, while the
	 * CODE column collates as utf8mb4_0900_ai_ci and equates a full-width digit with its ASCII twin - a prefix
	 * ending in U+FF19 and one ending in '9' are the same code to the database. Any ban list is therefore
	 * incomplete by construction: a ban on '%' leaves the full-width U+FF05, a ban on digits leaves U+FF19, and
	 * zero-width (U+200B) or soft-hyphen (U+00AD) characters slip through every one of them while collapsing to
	 * nothing on comparison. The allow-list answers the whole class at once, and bans '%', '{=' and every other
	 * expression character as a side effect.
	 *
	 * The "does not end with a digit" rule stays on top of the allow-list: digits are legal inside a prefix, but a
	 * digit right before the token would give templates 7 and 77 one and the same expanded code.
	 *
	 * The prefix length is capped because CODE is a varchar(64) and the stand runs with an empty sql_mode: a
	 * longer code is truncated silently, and two codes differing past the 64th character would land on one
	 * storage row. The cap leaves room for the expanded template id (10 digits covers the whole int range), so a
	 * code built to this rule always survives the column intact.
	 *
	 * The code is judged as written, without trimming: the column compares NO PAD, so a create node carrying
	 * 'X_{token} ' would make storage under a code the consumers of 'X_{token}' never reach, while a trimming
	 * barrier would call the two one and the same code.
	 */
	public static function isStrictTemplateBoundCode(mixed $code): bool
	{
		if (!is_string($code))
		{
			return false;
		}

		if (!str_ends_with($code, self::TEMPLATE_ID_TOKEN))
		{
			return false;
		}

		$prefix = substr($code, 0, -strlen(self::TEMPLATE_ID_TOKEN));

		return preg_match('/^[A-Za-z0-9_]{1,' . self::MAX_PREFIX_LENGTH . '}\z/', $prefix) === 1
			&& preg_match('/^[0-9]+$/D', substr($prefix, -1)) !== 1
		;
	}

	/**
	 * The code with the per-template token expanded for the bound template - the text the runtime addresses
	 * storage by, and therefore the only form a lookup by CODE can be made with. Null when the code carries a
	 * substitution this class does not read whole, so a caller can never search by a text the runtime would
	 * have expanded differently.
	 *
	 * Public because the reverse pipeline of another module resolves the columns of the storage a node writes
	 * into, and the one reading of the token this class owns has to answer there as well.
	 */
	public static function expandTemplateIdToken(mixed $code, int $templateId): ?string
	{
		if (!self::isFullyInterpretableCode($code))
		{
			return null;
		}

		return self::replaceTemplateIdTokens((string)$code, (string)$templateId);
	}

	private function isPureTokenCode(string $code): bool
	{
		return self::carriesTemplateIdToken($code) && self::isFullyInterpretableCode($code);
	}

	/**
	 * Whether the barrier understands the code WHOLE - the fail-closed rule the storage barriers rest on. Every
	 * spelling this class expands is replaced first; if anything expression-like survives, the runtime would
	 * expand something this barrier cannot read, so the code is refused instead of judged.
	 *
	 * The rule exists because the runtime is richer than it looks and the agent controls its input. An unresolved
	 * object name is not left alone: CBPActivity looks for a workflow ACTIVITY of that name
	 * ({@see \Bitrix\Bizproc\Runtime\Mixins\ActivityRuntimePropertyGetter::getRuntimeProperty()}), and block ids
	 * are authored by the agent - a block named 'workflow' makes '{=workflow:templateid}' expand to the empty
	 * string, turning 'PROJECT_PULSE{=workflow:templateid}' into the code of somebody else's storage. The same
	 * holds for '{=Variable:...}', '{=Document:...}' and every other object: enumerating what the barrier must
	 * understand can never be complete, so it enumerates what it DOES understand and refuses the rest.
	 *
	 * This predicate is a mirror of {@see \CBPActivity::getPropertyValueRecursive()}, and it is complete only as
	 * long as it answers EVERY way that method turns a value into something else. All five, in the order the
	 * runtime tries them (bizproc/classes/general/activity.php):
	 *   1. {@see \CBPActivity::parseExpression()} against ValuePattern (:28) - the whole string is one
	 *      substitution. Covered: such a string contains '{=';
	 *   2. the ARRAY branch (:562) - the only entry that takes no string at all. The method recurses into the
	 *      elements and then, at :596-607, resolves a two-element array of strings as an [object, field]
	 *      reference through GetRealParameterValue - a reference carrying no '{=' anywhere. It opens no hole
	 *      here because the barrier never receives such a value as a code: this predicate refuses a non-string
	 *      outright (is_string below), StorageNodeClassifier::extractStorageCode() yields null for a non-string
	 *      setting, and the wire contract declares the setting value a string. Were an array ever handed to this
	 *      predicate, it would be refused rather than silently read;
	 *   3. CalcPattern (:34), applied at :634 - the CALCULATOR, with two alternatives: a string whose first
	 *      non-space character is '=', and '{{=...}}'. The first alternative is why a leading '=' is refused
	 *      here: '=strtolower("TESTSTORAGE")' carries no substitution at all and still evaluates to a foreign
	 *      code. The pattern writes '^\s*' before the '=', so a padded '  =1+1' calculates too; that form is
	 *      already gone by then - edge whitespace is refused a step earlier, over the exact set PCRE '\s'
	 *      accepts, which is not PHP's default trim() set (it misses form feed, U+000C);
	 *   4. CalcInlinePattern (:35), applied at :651 - a computable expression anywhere inside the string.
	 *      Covered by the '{{' check, which also covers the '{{=...}}' alternative of the calculator above and
	 *      ValueSimplePattern (:29);
	 *   5. ValueInlinePattern (:30), applied at :664 - a substitution anywhere inside the string. Covered by
	 *      expanding what this class knows and refusing a surviving '{='.
	 * A sixth entry point added to the runtime makes this predicate incomplete again: extend the list with it.
	 *
	 * Edge whitespace is refused rather than trimmed: the CODE column compares NO PAD, so 'X ' and 'X' are two
	 * different rows to the database while a trimming barrier reads them as one code.
	 */
	public static function isFullyInterpretableCode(mixed $code): bool
	{
		if (!is_string($code))
		{
			return false;
		}

		if (self::hasEdgeWhitespace($code))
		{
			return false;
		}

		if (str_starts_with($code, '=') || str_contains($code, '{{'))
		{
			return false;
		}

		$expanded = self::replaceTemplateIdTokens($code, '');

		return $expanded !== null && !str_contains($expanded, '{=');
	}

	/** One reading of "padded" for the predicate above and for the refusal that tells the caller what to fix. */
	private static function hasEdgeWhitespace(string $code): bool
	{
		return trim($code, self::PCRE_WHITESPACE) !== $code;
	}

	/**
	 * The single normalisation of a storage code, shared by {@see StorageAuthorizationContext} indexing the owned
	 * and declared sets and by the create-node admission policy matching a code against the baseline: two
	 * implementations of it would let the barrier own what the sets do not.
	 *
	 * Case is folded - storage codes resolve case-insensitively in the database, so a case-shifted code addresses
	 * the same storage. Edge whitespace is NOT trimmed: the CODE column compares NO PAD, and a padded code is
	 * refused by isFullyInterpretableCode() before any comparison.
	 */
	public static function normalizeCode(string $code): string
	{
		return mb_strtolower($code);
	}

	private static function carriesTemplateIdToken(string $code): bool
	{
		if (preg_match_all(self::TEMPLATE_ID_TOKEN_PATTERN, $code, $matches) < 1)
		{
			return false;
		}

		foreach ($matches['field'] as $field)
		{
			if (self::isTemplateIdField($field))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Null when PCRE itself gave up - backtrack or recursion limit, JIT stack, malformed UTF-8. Every caller reads
	 * that as "the code was never expanded" and answers fail-closed: casting the null to a string would say instead
	 * that the expansion consumed the whole code, which is the opposite of what happened.
	 */
	private static function replaceTemplateIdTokens(string $code, string $replacement): ?string
	{
		return preg_replace_callback(
			self::TEMPLATE_ID_TOKEN_PATTERN,
			static fn(array $matches): string => self::isTemplateIdField($matches['field'])
				? $replacement
				: $matches[0],
			$code,
		);
	}

	/**
	 * The runtime strips a trailing '_printable' before it looks the field up (CBPActivity::getRealParameterValue,
	 * case-sensitively) and compares what is left with mb_strtolower, so 'TemplateId', 'templateid' and
	 * 'TemplateId_printable' all resolve to the template id.
	 */
	private static function isTemplateIdField(string $field): bool
	{
		if (str_ends_with($field, '_printable'))
		{
			$field = substr($field, 0, -strlen('_printable'));
		}

		return mb_strtolower($field) === 'templateid';
	}

	/**
	 * Takes the code with the per-template token already expanded, so a code the barrier could not expand never
	 * reaches the lookup as text.
	 *
	 * Memoised per guard instance (one reverse call): a template may carry dozens of consumer nodes sharing a
	 * handful of codes, and the lookups would otherwise run per node, turning the cheap validate into a load
	 * amplifier. Nothing creates storage within a call, so a memoised miss cannot go stale.
	 */
	private function resolveStorageTypeId(string $resolvedCode): ?int
	{
		if ($resolvedCode === '')
		{
			return null;
		}

		if (array_key_exists($resolvedCode, $this->storageTypeIdByCode))
		{
			return $this->storageTypeIdByCode[$resolvedCode];
		}

		// Exact match, not a LIKE: the code decides whether editing is banned, and a substring resolve would
		// answer for a foreign storage whose code merely starts with this one.
		$id = $this->storageTypeProvider->getByCode($resolvedCode)?->getId();
		$this->storageTypeIdByCode[$resolvedCode] = $id !== null ? (int)$id : null;

		return $this->storageTypeIdByCode[$resolvedCode];
	}

	private function isDataView(int $storageTypeId): bool
	{
		return $this->dataViewByStorageTypeId[$storageTypeId] ??=
			$this->dataViewProvider->getByStorageTypeId($storageTypeId) !== null;
	}
}

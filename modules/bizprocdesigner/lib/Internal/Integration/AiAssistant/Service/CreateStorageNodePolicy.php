<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Admits a storage CREATE node absent from the published template baseline (ALG-10). The immutability rule
 * (ALG-01) answers only for nodes a published template already carries; on a template that never published a
 * storage node the baseline is empty, so without this policy an agent could not create storage at all.
 *
 * The rule order is normative:
 *   1. any other node type is refused unread, setuptemplateactivity included: the wizard configures the template
 *      as a whole and touches no storage, so it is a node of another family here rather than one this policy
 *      could ever admit - outside the baseline it is judged by {@see SetupTemplateNodePolicy}, not banned.
 *      Defensive duplication: the reverse pipeline never asks this policy about such a node - both call sites in
 *      {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator} judge the
 *      type before they ask, and the wizard branches off to its own policy - so this rule answers a direct caller
 *      alone and keeps the policy fail-closed on its own contract instead of on the caller's;
 *   2. a StorageId that addresses storage is refused: the runtime prefers such an id over the code, so the code
 *      this policy judges would not be the storage the node actually reaches;
 *   3. a code strictly bound to the template is allowed - its expansion is provably confined to this template;
 *   4. a code the published template already created is allowed whatever its form, as long as the barrier reads
 *      the code whole: the storage exists and the template owns it, so the shape proves nothing further, while a
 *      code carrying a substitution the barrier cannot expand would be inherited as TEXT and created under
 *      something else entirely (see isBaselineCreatorCode);
 *   5. any other code is refused.
 *
 * The policy is resource-based, never subject-based: the subject is authorised once per entry by
 * {@see OperationAuthorizationGuard}, and re-checking rights per node is forbidden by the reverse-entry
 * contract. It reads no database either - the storage is created at an arbitrary later point of the run, so a
 * verdict resting on the current state of storage would already be stale by the time it matters.
 *
 * Inheritance is judged against baselineCreatorStorageCodes alone, never against the create nodes of the graph
 * under validation, or two forged create nodes would legitimise each other. The code FORM is judged by the
 * stricter isStrictTemplateBoundCode rather than the consumer-side isPureTokenCode: a consumer with a suffix
 * after the token round-trips today, whereas a create node is authored here and now and can be held to the
 * provable form.
 *
 * admit() is pure: the validator calls it twice per pass - once to collect the owned codes, once for the
 * verdict - so a side effect or a memo would let the second verdict differ from the first.
 */
final class CreateStorageNodePolicy
{
	public const REASON_ID_ADDRESSING = 'create storage node must not address storage by id; address it by code';

	/**
	 * Names every condition the code form is judged by, word for word as the node schema
	 * (bizproc/install/activities/bitrix/createstoragenode/.ai.php) advertises them: the refusal is the agent's
	 * only channel of self-correction, and a condition missing here leaves it re-sending a code that reads as
	 * satisfying every rule it was told about.
	 */
	public const REASON_CODE_NOT_OWNED =
		'storage code must end with the {=Workflow:TemplateId} substitution placed after a non-empty prefix of up '
		. 'to 50 latin letters, digits and underscores that does not end with a digit, or be a code the published '
		. 'template already created; either way the code must carry no leading or trailing whitespace';

	/**
	 * The wording an immutable storage node outside the baseline is refused with when no policy answers for it -
	 * a type neither this policy nor {@see SetupTemplateNodePolicy} judges, and a create node reaching the
	 * validator with no template context to bind a code to. Shared with
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator}, which emits it
	 * in exactly those cases: one source of the text, so the two refusals cannot drift apart.
	 */
	public const REASON_TYPE_NOT_ALLOWED = 'immutable storage node does not match the published template baseline';

	private const LOGGER_ID = 'bizprocdesigner.aiassistant.storage_create_policy';

	/** Longest agent-authored value a diagnostic record keeps: neither a storage code nor a block id is bounded. */
	private const LOG_VALUE_MAX_LENGTH = 128;

	private ?LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	public function admit(
		string $type,
		AgentSettingCollection $settings,
		ValidationContext $context,
	): AuthorizationVerdict {
		if (!StorageNodeClassifier::isStorageCreator($type))
		{
			return AuthorizationVerdict::reject(self::REASON_TYPE_NOT_ALLOWED);
		}

		if (StorageReferenceGuard::addressesStorageById(StorageNodeClassifier::extractStorageId($settings)))
		{
			return AuthorizationVerdict::reject(self::REASON_ID_ADDRESSING);
		}

		$code = StorageNodeClassifier::extractStorageCode($settings) ?? '';

		if (StorageReferenceGuard::isStrictTemplateBoundCode($code))
		{
			return AuthorizationVerdict::allow();
		}

		if (self::isBaselineCreatorCode($code, $context))
		{
			return AuthorizationVerdict::allow();
		}

		return AuthorizationVerdict::reject(self::REASON_CODE_NOT_OWNED);
	}

	/**
	 * Records a refusal in the module diagnostic channel, so every reverse entry keeps the refusal observable.
	 * Deliberately outside admit(): the verdict is asked twice per pass - once to collect the owned codes, once
	 * for the verdict the agent sees - and a write inside the pure method would double every refusal. The record
	 * names the template and the entry, which the caller does not pass on: the agent gets the reason alone.
	 */
	public function reportRejection(
		string $blockId,
		AgentSettingCollection $settings,
		AuthorizationVerdict $verdict,
		ValidationContext $context,
	): void {
		$this->getLogger()->warning(
			'AI reverse entry: storage create node refused',
			[
				'templateId' => $context->templateId,
				'source' => $context->source->value,
				'blockId' => self::forLog($blockId),
				'storageCode' => self::forLog(StorageNodeClassifier::extractStorageCode($settings) ?? ''),
				'reason' => $verdict->reason,
			],
		);
	}

	/**
	 * The block id and the storage code are authored by the agent and bounded by nothing, while one entry carries
	 * up to 256 blocks: written as they came, a refusal with newlines would forge records of its own inside the
	 * channel and a kilobyte-long code would push the real records out of it. Control characters collapse into a
	 * single space and the value is cut to a length that still names the node.
	 *
	 * A null is PCRE giving up, not a cleaned value: casting it to a string would leave the refusal naming no
	 * node at all, so the value is logged as it stands - a record that reads awkwardly still beats a blank one.
	 */
	private static function forLog(string $value): string
	{
		$singleLine = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? $value;

		return mb_strlen($singleLine) > self::LOG_VALUE_MAX_LENGTH
			? mb_substr($singleLine, 0, self::LOG_VALUE_MAX_LENGTH) . '...'
			: $singleLine;
	}

	/**
	 * Fail-closed on both sides of the comparison, exactly as
	 * {@see \Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext} indexes and answers for a code: a
	 * code the barrier cannot read WHOLE inherits nothing. Inheritance is decided by TEXT while the runtime creates
	 * storage under what the text expands to, so 'DAY_PLANNER_{=workflow:templateid}' would otherwise read as the
	 * baseline 'DAY_PLANNER_{=Workflow:TemplateId}' - the object of a substitution is case-sensitive to the runtime
	 * and not to a text match - and the node would reconfigure storage bound to no template at all.
	 *
	 * What survives the predicate is compared through {@see StorageReferenceGuard::normalizeCode()} - the very
	 * function the owned set is indexed by, so a code this policy admits by inheritance is the code the reference
	 * rules then recognise as owned. Two normalisations of their own would let the two sides disagree on what one
	 * code is.
	 */
	private static function isBaselineCreatorCode(string $code, ValidationContext $context): bool
	{
		if (!StorageReferenceGuard::isFullyInterpretableCode($code))
		{
			return false;
		}

		$normalizedCode = StorageReferenceGuard::normalizeCode($code);
		if ($normalizedCode === '')
		{
			return false;
		}

		foreach ($context->baselineCreatorStorageCodes as $baselineCode)
		{
			$baselineCode = (string)$baselineCode;
			if (
				StorageReferenceGuard::isFullyInterpretableCode($baselineCode)
				&& StorageReferenceGuard::normalizeCode($baselineCode) === $normalizedCode
			)
			{
				return true;
			}
		}

		return false;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}

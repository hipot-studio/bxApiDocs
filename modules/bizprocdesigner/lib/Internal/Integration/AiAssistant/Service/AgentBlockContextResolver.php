<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Public\Service\Storage\StorageReferenceGuard;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingValueValidator;
use Bitrix\Main\Result;

/**
 * The property values a block of the catalog is described in the context of, read from what the caller sent.
 *
 * Judged against the description the block was already built with, so the schema that answers for a name is
 * the one the agent was given. Only the settings that description publishes are admitted: a carried or a
 * server-owned name is refused rather than quietly dropped, because a context that was ignored would answer
 * with a schema the caller believes it asked for and did not get.
 *
 * The check is the one the write path runs on the same value
 * ({@see AgentSettingValueValidator}), so a value accepted here is a value the graph would accept, and the
 * normalized form it yields is the form the block is described with.
 *
 * Two kinds of value are held to more than their own schema, because describing a block in the context of one
 * would answer with what the token does not authorise: a document type the bound template may not name
 * ({@see self::isTrustedIfDocumentType()}) and a storage the bound template neither owns nor declares
 * ({@see self::authorizedStorageReference()}). The two are answered differently on purpose. A foreign
 * document type is refused as an incorrect value, because no other type is the right one to send. A foreign
 * storage is not refused at all: the context is accepted and the address is rewritten to one that resolves to
 * nothing ({@see self::UNRESOLVABLE_STORAGE_ADDRESS}), so the block comes back with its value map declared
 * dynamic and its keys simply not computed. That is the answer a storage that does not exist yet already gets,
 * and the agent has a legitimate way to arrive here: the one that creates a storage in the graph it is writing
 * names its own future code, which no published version can own yet. Refusing it would tell that agent to
 * correct a code that is already correct. Both remain fail-closed without a predicate to judge them by.
 *
 * An authorised storage code is also REPLACED by the one the caller's predicate answers with: a per-template
 * code is declared as `DAY_PLANNER_{=Workflow:TemplateId}` and exists as `DAY_PLANNER_198`, so the spelling
 * that proves ownership and the spelling that finds the storage are two, and the block has to be described by
 * the second.
 */
final class AgentBlockContextResolver
{
	private const PATH = 'contextSettings';

	/**
	 * The two names a context addresses storage by, spelled as the graph path reads them off a storage node
	 * ({@see StorageNodeClassifier}). They are named here as well because the gateway has to recognise them in a
	 * raw `{name, value}` pair, which is not a node and carries no settings collection to classify.
	 */
	public const STORAGE_CODE_SETTING = 'StorageCode';
	public const STORAGE_ID_SETTING = 'StorageId';

	/**
	 * The address every unauthorised storage reference is rewritten to, and the reason none of them can be told
	 * apart in the answer.
	 *
	 * It is read by {@see \CBPAiWriteDataStorageActivity::addressesStorage()} as "the context named a storage",
	 * which is what declares the value map dynamic, and by
	 * {@see \Bitrix\Bizproc\Internal\Service\StorageActivity\StorageActivityService::resolveStorageId()} as
	 * nothing at all: an id that is not positive falls through to the code, and the code is empty. A negative id
	 * cannot become a real address later either - storage ids are positive by construction - which is why the
	 * refusal is expressed as this pair rather than as a made-up code that some portal might one day carry.
	 *
	 * It replaces the WHOLE address rather than the offending half: a request naming a code this template owns
	 * and an id it does not is one ambiguous address, the runtime would prefer the id, and answering by the
	 * code would describe a storage the node would not write into.
	 */
	private const UNRESOLVABLE_STORAGE_ADDRESS = [
		self::STORAGE_ID_SETTING => -1,
		self::STORAGE_CODE_SETTING => '',
	];

	/**
	 * @param mixed $contextSettings the raw field of the request; null or an empty list means no context
	 * @param \Closure(DocumentDescription): bool|null $isTrustedDocumentType whether the bound template may
	 *        name this document type ({@see TrustedDocumentTypeResolver}). Without it no setting carrying a
	 *        document type is admitted at all: a caller that cannot say which types are trusted must not be
	 *        the one deciding, and describing the block in the abstract is the safe half of the answer
	 * @param \Closure(string): ?string|null $authorizeStorageCode the code the block is to be described by when
	 *        the bound template may address the storage this one names, null when it may not
	 *        ({@see StorageColumnSchemaResolver::authorizedLookupCode()}). It answers with a code rather than
	 *        with a flag because the spelling that authorises is not always the spelling that finds the storage:
	 *        a per-template code is declared with the `{=Workflow:TemplateId}` substitution and exists expanded.
	 *        Without the closure no address reaches the description, for the same reason no document type does
	 *
	 * @return Result data `['properties' => array<string, mixed>]` when accepted, addressed errors otherwise
	 */
	public function resolve(
		mixed $contextSettings,
		ActivityAiDescriptionResult $baseDescription,
		?\Closure $isTrustedDocumentType = null,
		?\Closure $authorizeStorageCode = null,
	): Result
	{
		$result = (new Result())->setData(['properties' => []]);

		if ($contextSettings === null || $contextSettings === [])
		{
			return $result;
		}

		if (!is_array($contextSettings) || !array_is_list($contextSettings))
		{
			return $result->addError(GraphError::at(
				self::PATH,
				self::PATH . ' expected a list of {name, value} pairs',
				GraphErrorCode::SettingEntryInvalid,
			));
		}

		$properties = [];
		$seenNames = [];
		$addressesUnauthorizedStorage = false;
		foreach ($contextSettings as $index => $entry)
		{
			$path = self::PATH . '.' . $index;
			if (!is_array($entry))
			{
				$result->addError(
					GraphError::at($path, "{$path} expected type is object", GraphErrorCode::SettingEntryInvalid),
				);

				continue;
			}

			$name = $entry['name'] ?? null;
			if (!is_string($name) || $name === '')
			{
				$result->addError(GraphError::at(
					"{$path}.name",
					"{$path}.name should be not empty string",
					GraphErrorCode::SettingEntryInvalid,
				));

				continue;
			}

			// Refused rather than collapsed into the last pair, by the rule the settings validator judges a
			// repeated name on the graph path by: which of two values a block is described in the context of
			// may not depend on the order they arrived in.
			if (isset($seenNames[$name]))
			{
				$result->addError(GraphError::at(
					"{$path}.name",
					"{$path}.name is duplicated",
					GraphErrorCode::SettingNameDuplicated,
				));

				continue;
			}

			$seenNames[$name] = true;

			$setting = $baseDescription->settings->findFirstByName($name);
			if ($setting === null)
			{
				$result->addError(GraphError::at(
					"{$path}.name",
					$this->refusedNameMessage($name, $path, $baseDescription),
					GraphErrorCode::SettingNameUnknown,
				));

				continue;
			}

			if (!array_key_exists('value', $entry))
			{
				$result->addError(GraphError::at(
					"{$path}.value",
					"{$path}.value is required",
					GraphErrorCode::SettingValueInvalid,
				));

				continue;
			}

			// No validation context is passed: the carried-value passthrough of the option check unlocks values a
			// published template already holds, and a request for a description is not that template.
			$validator = new AgentSettingValueValidator();
			$valueResult = $validator->validate(self::readableValue($entry['value']), $path, $setting);
			if (!$valueResult->isSuccess())
			{
				$result->addErrors($valueResult->getErrors());

				continue;
			}

			// The normalized form, not the submitted one: the same value has to describe the block the way it
			// would be persisted, or the schema answers for a shape the graph would then store differently.
			$value = $validator->getExpectedTypeValue();

			if (!self::isTrustedIfDocumentType($setting, $value, $isTrustedDocumentType))
			{
				$result->addErrors([GraphError::at(
					"{$path}.value",
					"{$path}.value is incorrect",
					GraphErrorCode::SettingValueInvalid,
				)]);

				continue;
			}

			if (self::addressesStorage($name, $value))
			{
				// The address the template may not read is dropped rather than carried and rather than refused:
				// the whole address is rewritten below, and a value kept here would reach the description
				// through the half the rewrite does not name.
				$lookupCode = $addressesUnauthorizedStorage
					? null
					: self::authorizedStorageReference($name, $value, $authorizeStorageCode)
				;
				if ($lookupCode === null)
				{
					$addressesUnauthorizedStorage = true;

					continue;
				}

				// The code the storage really carries, which is not always the one that was sent: the block has
				// to be described by the text the runtime addresses storage by, or a per-template code would
				// authorise and then find nothing.
				$value = $lookupCode;
			}

			$properties[$name] = $value;
		}

		if ($addressesUnauthorizedStorage)
		{
			// Every half of the address goes, including one that passed on its own: a code this template owns is
			// still the wrong answer beside an id it does not. Dropped by the same case-insensitive reading the
			// admission used, so no spelling of a name can leave a value behind the rewrite.
			$properties = array_filter(
				$properties,
				static fn(string $name): bool => !self::isStorageAddressName($name),
				ARRAY_FILTER_USE_KEY,
			);

			$properties += self::UNRESOLVABLE_STORAGE_ADDRESS;
		}

		return $result->isSuccess() ? $result->setData(['properties' => $properties]) : $result;
	}

	/**
	 * A setting that carries a document type may only carry one the bound template is allowed to name.
	 *
	 * Without this the context was a way around the whole rule. A `document_type` setting - `Document` of the
	 * crm entity triggers - publishes no option list and holds any string, so an agent bound to a deal
	 * template could send `{name: "Document", value: "crm@CCrmDocumentLead@LEAD"}` and be answered with the
	 * block described for the lead: its field codes, its statuses, its funnels. The context does not grant
	 * access and does not widen the set of types this template may address (ADR D4, BRIEF § 3.3), so the value
	 * is held to the same server-built set the field read is held to, and refused as an incorrect value when
	 * it is outside it - not as a refusal of its own, because a context that named a foreign type is a wrong
	 * value like any other.
	 *
	 * Settings of every other type are not the concern of this check and pass it untouched.
	 *
	 * @param \Closure(DocumentDescription): bool|null $isTrustedDocumentType
	 */
	private static function isTrustedIfDocumentType(
		Setting $setting,
		mixed $value,
		?\Closure $isTrustedDocumentType,
	): bool
	{
		if ((string)$setting->type !== FieldType::DOCUMENT_TYPE)
		{
			return true;
		}

		// An empty value names no type and narrows nothing: it is the block described as it already was.
		if (!is_string($value) || $value === '')
		{
			return true;
		}

		$documentType = DocumentDescription::fromCanonicalString($value);

		return $documentType !== null
			&& $isTrustedDocumentType !== null
			&& $isTrustedDocumentType($documentType)
		;
	}

	/**
	 * Whether this pair names a storage at all.
	 *
	 * An empty half names none and narrows nothing - it is the block described as it already was - so it is left
	 * alone rather than rewritten: rewriting it would declare the value map dynamic on a context that asked for
	 * no storage. "No id" is read by the predicate the guard and the reverse pipeline share, so the barrier and
	 * the runtime agree on what an id addresses.
	 *
	 * Names are compared without regard to case, exactly as the graph path reads them off a node
	 * ({@see StorageNodeClassifier}): a case-shifted name reaches the same activity property.
	 */
	private static function addressesStorage(string $name, mixed $value): bool
	{
		if (strcasecmp($name, self::STORAGE_ID_SETTING) === 0)
		{
			return StorageReferenceGuard::addressesStorageById($value);
		}

		return strcasecmp($name, self::STORAGE_CODE_SETTING) === 0 && is_string($value) && $value !== '';
	}

	/** Whether a name is one of the two the address is written in, whatever the value behind it. */
	private static function isStorageAddressName(string $name): bool
	{
		return strcasecmp($name, self::STORAGE_ID_SETTING) === 0
			|| strcasecmp($name, self::STORAGE_CODE_SETTING) === 0
		;
	}

	/**
	 * The code the block is described by when the bound template may address the storage this pair names, null
	 * when it may not.
	 *
	 * The columns of a storage - their codes, names, types, requiredness and options - are published to an
	 * administrator alone on every other surface, while `catalog.block.get` answered a `StorageCode` of any
	 * storage of the portal with them. The graph path judges the very same reference by ownership
	 * ({@see StorageColumnSchemaResolver}), so it is judged by ownership here, through the very same rule - and
	 * answered with a code rather than a flag, because that rule also decides which spelling finds the storage.
	 *
	 * An id address is never authorised, whoever owns the storage behind it: the runtime prefers a StorageId
	 * over the code and verifies nothing about it (ALG-02 rule 1), and the sole exception that rule leaves - an
	 * unchanged node of the published baseline - is a node of a graph, which a description context is not.
	 *
	 * @param \Closure(string): ?string|null $authorizeStorageCode
	 */
	private static function authorizedStorageReference(
		string $name,
		mixed $value,
		?\Closure $authorizeStorageCode,
	): ?string
	{
		if (strcasecmp($name, self::STORAGE_CODE_SETTING) !== 0 || !is_string($value) || $authorizeStorageCode === null)
		{
			return null;
		}

		$lookupCode = $authorizeStorageCode($value);

		return is_string($lookupCode) && $lookupCode !== '' ? $lookupCode : null;
	}

	/**
	 * A name the published schema does not hold is refused for one of two reasons, and they are told apart:
	 * the block may have no such setting at all, or the setting may exist with a value that is not the
	 * agent's to write. Naming the second as unknown would send the caller looking for a spelling mistake in
	 * a name it spelled correctly.
	 */
	private function refusedNameMessage(string $name, string $path, ActivityAiDescriptionResult $baseDescription): string
	{
		$isKnownButNotEditable =
			in_array($name, $baseDescription->carriedSettings, true)
			|| in_array($name, $baseDescription->skippedSettings, true)
		;

		return $isKnownButNotEditable
			? "{$path}.name is not a setting this block can be described in the context of: its value is not written by the agent"
			: "{$path}.name is incorrect"
		;
	}

	/**
	 * The submitted value in the shape the settings schema is written against.
	 *
	 * A JSON number is the same value in another transport shape and is read by its string form, the way the
	 * option check reads it; null stands for no value. Every other shape reaches the type check of the
	 * setting unchanged and is refused there if the setting cannot hold it - guessing what a boolean means
	 * for a given setting would accept a value the graph would then reject.
	 */
	private static function readableValue(mixed $value): mixed
	{
		if ($value === null)
		{
			return '';
		}

		return is_int($value) || is_float($value) ? (string)$value : $value;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;

/**
 * The closed set of refusals the dictionary domain reports.
 *
 * The case name is the code that leaves the domain: it travels as {@see Error::getCode()}, and a consumer
 * recovers it with `DictionaryError::tryFrom($error->getCode())`. Every refusal is one of these - neither
 * a message of the storage boundary nor a text of the legacy facade ever reaches a caller, because either
 * could name a table or a detail of the storage.
 *
 * `VALUE_NOT_FOUND` is also the answer for a value that exists in another dictionary, or as a stage of a
 * pipeline: those are not values of the dictionary that was addressed, and the domain neither reads nor
 * changes them. And it is the answer for a value erased while the operation addressing it was running:
 * what the client addressed is not in the dictionary, whichever moment it left.
 *
 * `VALUE_ID_NOT_ALLOWED` and `VALUE_ID_DUPLICATE` are the two ways a given identifier is refused on
 * creation - unusable in itself, and taken already. `FIELD_NOT_WRITABLE` and `FIELD_VALUE_NOT_ALLOWED`
 * are the same pair for the remaining fields: the first says the field is not written at all, the second
 * that the value given to it is not one the domain accepts.
 *
 * `DICTIONARY_OPERATION_FAILED` is what a refusal of the storage boundary the domain cannot name becomes.
 * It is always logged: it is a gap of the refusal mapper, a dictionary already poisoned by data written
 * past the facade, or a write the boundary could not hold apart from another write of the same value.
 *
 * A refusal about one field names it in `['field' => ...]` of {@see Error::getCustomData()}, and for the
 * four that can only be about the identifier the field is not up to a caller to pass - see {@see toError()}.
 *
 * @internal
 */
enum DictionaryError: string
{
	case ACCESS_DENIED = 'ACCESS_DENIED';
	case VALUE_NOT_FOUND = 'VALUE_NOT_FOUND';
	case VALUE_ID_NOT_ALLOWED = 'VALUE_ID_NOT_ALLOWED';
	case VALUE_ID_DUPLICATE = 'VALUE_ID_DUPLICATE';
	case FIELD_VALUE_NOT_ALLOWED = 'FIELD_VALUE_NOT_ALLOWED';
	case FIELD_NOT_WRITABLE = 'FIELD_NOT_WRITABLE';
	case SYSTEM_VALUE_NOT_DELETABLE = 'SYSTEM_VALUE_NOT_DELETABLE';
	case DICTIONARY_OPERATION_FAILED = 'DICTIONARY_OPERATION_FAILED';

	/** Custom data key: the field a refusal is about. */
	public const CUSTOM_DATA_FIELD = 'field';

	/** The phrase of a case is its name under one prefix, in the language twin of this file. */
	private const PHRASE_KEY_PREFIX = 'CRM_V2_DICTIONARY_ERROR_';

	/**
	 * $field is for the refusals that can be about any field. The ones that can only be about the
	 * identifier name it themselves, so a caller cannot report them without it.
	 */
	public function toError(?DictionaryField $field = null): Error
	{
		$named = $field ?? $this->getInherentField();

		return new Error(
			$this->getDeferredMessage(),
			$this->value,
			$named === null ? null : [self::CUSTOM_DATA_FIELD => $named->value],
		);
	}

	/**
	 * The phrase of the refusal, left to be resolved when it is published rather than when it is
	 * reported: a client of REST names the language of its answer, and the transport localizes the
	 * message of an error only for as long as it is still a phrase
	 * ({@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()}). A text resolved
	 * here would reach every client in the language of the portal instead, and the three languages the
	 * product ships would never be read at all.
	 *
	 * The file is named rather than guessed: {@see LocalizableMessage} takes the file of its caller for
	 * the owner of the phrase, and every caller of this vocabulary is somewhere else.
	 */
	private function getDeferredMessage(): LocalizableMessage
	{
		return new LocalizableMessage(self::PHRASE_KEY_PREFIX . $this->value, null, null, __FILE__);
	}

	private function getInherentField(): ?DictionaryField
	{
		return match ($this)
		{
			self::VALUE_NOT_FOUND,
			self::VALUE_ID_NOT_ALLOWED,
			self::VALUE_ID_DUPLICATE,
			self::SYSTEM_VALUE_NOT_DELETABLE => DictionaryField::Id,
			self::ACCESS_DENIED,
			self::FIELD_VALUE_NOT_ALLOWED,
			self::FIELD_NOT_WRITABLE,
			self::DICTIONARY_OPERATION_FAILED => null,
		};
	}
}

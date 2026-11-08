<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * The closed set of refusals the requisite domain reports.
 *
 * The case name is the code that leaves the domain: it travels as {@see Error::getCode()}, and a
 * consumer recovers it with `RequisiteError::tryFrom($error->getCode())`. Every refusal of the group is
 * one of these - neither an exception of the storage boundary nor an error text of its own ever reaches
 * a caller.
 *
 * ### Absence is one answer, and that is the whole point
 *
 * `REQUISITE_NOT_FOUND` is also the answer when the requisite is there and the user may not read it, and
 * when it is there but belongs to another owner than the one the caller addressed. `OWNER_NOT_FOUND` is
 * the same three answers about the owner: absent, hidden by permissions, or of a type no requisite
 * belongs to. Telling any of them apart would let a client learn by trying which requisites and which
 * counterparties a portal keeps, so the group answers one code and the phrase of it says nothing more
 * either.
 *
 * That is why the set below has no case for "the owner is not yours" and none for "this type of owner
 * has no requisites": they are not separate answers of this domain, they are `OWNER_NOT_FOUND`.
 *
 * ### The refusals of a field
 *
 * `FIELD_NOT_WRITABLE`, `FIELD_NOT_IN_PRESET`, `FIELD_REQUIRED` and `FIELD_TOO_LONG` each name the field
 * they are about under `field` in {@see Error::getCustomData()} - a client that cannot tell which of the
 * fields it named was refused cannot correct the call. `FIELD_TOO_LONG` carries the limit under
 * `maxLength` as well, and it is the one phrase of this set that states a value of the refusal rather
 * than describing it. Being the only phrase built around a number, it is also the only one written in
 * the plural forms of the language: the limit picks the form.
 *
 * A write reports all the refusals of its fields at once rather than the first one, so more than one of
 * these may arrive together, each about a field of its own.
 *
 * ### The storage
 *
 * `REQUISITE_OPERATION_FAILED` is what is left when the storage would not write the row for a reason none
 * of the checks of the domain foresaw. It carries one phrase and no detail: the text of such a refusal
 * names columns, constraints and classes, and it goes to the log rather than to the client.
 */
enum RequisiteError: string
{
	/** The contact or the company of the requisite: absent, not readable by this user, or of a type no requisite belongs to. */
	case OWNER_NOT_FOUND = 'OWNER_NOT_FOUND';

	/** The requisite: absent, not readable by this user, or belonging to another owner than the addressed one. */
	case REQUISITE_NOT_FOUND = 'REQUISITE_NOT_FOUND';

	/** The preset: absent, or one the portal no longer offers - a requisite may not be created against it either way. */
	case PRESET_NOT_FOUND = 'PRESET_NOT_FOUND';

	/** The field is not one a write of the group takes from a caller. */
	case FIELD_NOT_WRITABLE = 'FIELD_NOT_WRITABLE';

	/** The preset of the requisite does not declare the field, so a requisite of that jurisdiction means nothing by it. */
	case FIELD_NOT_IN_PRESET = 'FIELD_NOT_IN_PRESET';

	/** The field is required by the preset of the requisite and was left empty. */
	case FIELD_REQUIRED = 'FIELD_REQUIRED';

	/** The value of the field is longer than the field takes; the limit travels with the refusal. */
	case FIELD_TOO_LONG = 'FIELD_TOO_LONG';

	/** The storage would not write the requisite, and the domain cannot say more than that. */
	case REQUISITE_OPERATION_FAILED = 'REQUISITE_OPERATION_FAILED';

	/**
	 * The phrase of the refusal, in the language of the request.
	 *
	 * @param array<string, int|string> $replace the values the phrase names, by placeholder. Only
	 *        {@see self::FIELD_TOO_LONG} has one, and {@see self::toError()} fills it from the refusal
	 *        itself - so a caller building an error by hand is the only one that needs this argument.
	 */
	public function getMessage(array $replace = []): string
	{
		Loc::loadMessages(__FILE__);

		$code = 'CRM_V2_REQUISITE_ERROR_' . $this->value;
		$limit = $this->limitTheFormIsChosenBy($replace);

		$message = $limit === null
			? Loc::getMessage($code, $replace)
			: Loc::getMessagePlural($code, $limit, $replace)
		;

		return (string)$message;
	}

	/**
	 * The refusal as an error of the kernel: the code of this case, its phrase, and whatever the refusal
	 * is about.
	 *
	 * @param array<string, int|string> $customData what the refusal names - the field it is about and,
	 *        for a length, the limit. The phrase is built from it, so a refusal assembled here never
	 *        answers with a placeholder left in its text.
	 */
	public function toError(array $customData = []): Error
	{
		return new Error(
			$this->getMessage($this->replacementsFrom($customData)),
			$this->value,
			$customData === [] ? null : $customData,
		);
	}

	/**
	 * The number the plural form of the phrase is chosen by, or null when there is no form to choose:
	 * every phrase but {@see self::FIELD_TOO_LONG} is one text, and a caller that states no limit gets
	 * the phrase with its placeholder left in.
	 *
	 * @param array<string, int|string> $replace
	 */
	private function limitTheFormIsChosenBy(array $replace): ?int
	{
		$limit = $replace['#MAX_LENGTH#'] ?? null;

		if ($this !== self::FIELD_TOO_LONG || !is_numeric($limit))
		{
			return null;
		}

		return (int)$limit;
	}

	/**
	 * @param array<string, int|string> $customData
	 * @return array<string, int|string>
	 */
	private function replacementsFrom(array $customData): array
	{
		if ($this !== self::FIELD_TOO_LONG || !isset($customData['maxLength']))
		{
			return [];
		}

		return ['#MAX_LENGTH#' => $customData['maxLength']];
	}
}

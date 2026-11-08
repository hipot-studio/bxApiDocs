<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

/**
 * The bank details of a requisite - the account a counterparty is paid through, kept as a record of its
 * own. A requisite may have several of them, ordered as the card of the requisite orders them.
 *
 * Which of the bank fields below the record has at all is decided by the jurisdiction of its requisite -
 * the country of the preset the requisite names - and not by the record: a Russian account is named by a
 * bank code and a correspondent account, a German one by a routing number and an IBAN, and neither
 * jurisdiction has a field for what the other asks. So a bank field carries two different absences and
 * they must not be confused:
 *
 * - `null` - the jurisdiction of the requisite has no such field, and it is not part of the record. A
 *   consumer leaves it out of what it builds rather than showing it empty.
 * - `''` - the field is part of the record and was left empty, which is a value like any other.
 *
 * The fields that are not of any jurisdiction - the name of the record, its comment, its activity and its
 * ordering - are always there, so they are strings and never `null`.
 *
 * The country identifier the row keeps is not here: it is a detail of storage, it is written from the
 * preset of the requisite in the first place, and publishing it would offer a second answer to a question
 * the preset already answers.
 *
 * Read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteBankDetailRepository}
 * is the only place that puts one together, and the only place that knows which fields a jurisdiction has.
 *
 * @internal
 */
final readonly class RequisiteBankDetail
{
	public const FIELD_ID = 'id';
	public const FIELD_REQUISITE_ID = 'requisiteId';
	public const FIELD_NAME = 'name';
	public const FIELD_ACTIVE = 'active';
	public const FIELD_SORT = 'sort';
	public const FIELD_COMMENTS = 'comments';

	public const FIELD_BANK_NAME = 'bankName';
	public const FIELD_BANK_ADDRESS = 'bankAddress';
	public const FIELD_BANK_CODE = 'bankCode';
	public const FIELD_BANK_ROUTING_NUMBER = 'bankRoutingNumber';
	public const FIELD_AGENCY_NAME = 'agencyName';
	public const FIELD_BIK = 'bik';
	public const FIELD_MFO = 'mfo';
	public const FIELD_ACCOUNT_NAME = 'accountName';
	public const FIELD_ACCOUNT_NUMBER = 'accountNumber';
	public const FIELD_ACCOUNT_TYPE = 'accountType';
	public const FIELD_ACCOUNT_CURRENCY = 'accountCurrency';
	public const FIELD_CORRESPONDENT_ACCOUNT_NUMBER = 'correspondentAccountNumber';
	public const FIELD_IIK = 'iik';
	public const FIELD_IBAN = 'iban';
	public const FIELD_SWIFT = 'swift';
	public const FIELD_BIC = 'bic';
	public const FIELD_CODE_B = 'codeB';
	public const FIELD_CODE_G = 'codeG';
	public const FIELD_RIB = 'rib';

	/**
	 * @param string $name the name of the record as its owner gave it - the name of the bank is
	 *        `bankName`, and the two are filled in independently.
	 * @param bool $active whether the record is offered where bank details are chosen; an inactive one is
	 *        still a record of its requisite.
	 * @param string|null $bik the bank code of the CIS jurisdictions, and `null` everywhere else - as
	 *        every field from here down is `null` outside the jurisdictions that have it.
	 */
	public function __construct(
		public int $id,
		public int $requisiteId,
		public string $name,
		public bool $active,
		public int $sort,
		public string $comments,
		public ?string $bankName,
		public ?string $bankAddress,
		public ?string $bankCode,
		public ?string $bankRoutingNumber,
		public ?string $agencyName,
		public ?string $bik,
		public ?string $mfo,
		public ?string $accountName,
		public ?string $accountNumber,
		public ?string $accountType,
		public ?string $accountCurrency,
		public ?string $correspondentAccountNumber,
		public ?string $iik,
		public ?string $iban,
		public ?string $swift,
		public ?string $bic,
		public ?string $codeB,
		public ?string $codeG,
		public ?string $rib,
	)
	{
	}
}

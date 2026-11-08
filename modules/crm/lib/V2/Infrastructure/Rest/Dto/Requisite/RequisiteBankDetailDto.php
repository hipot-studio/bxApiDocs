<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The bank details of a requisite, as the methods of the group answer them inside the requisite they
 * belong to - the account a counterparty is paid through, kept as a record of its own.
 *
 * A VARYING COMPOSITION, AND IT IS THE POINT. Which of the bank fields a record has at all is decided by
 * the jurisdiction of its requisite - the country of the preset the requisite names: a Russian account is
 * named by a bank code and a correspondent account, a German one by a routing number and an IBAN, and
 * neither jurisdiction has a field for what the other asks. Declared below is the union of every
 * jurisdiction the product knows, and an answer carries the part of it the preset stands for:
 *
 * - a field of the jurisdiction is answered even when it is empty, as an empty string;
 * - a field of another jurisdiction is not answered at all - the property is left uninitialized and
 *   therefore drops out of the response ({@see Dto::toArray()}).
 *
 * That is why no jurisdiction field is nullable: `null` is the way the domain says "not of this
 * jurisdiction" ({@see \Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteBankDetail}), and turning it into
 * an answered `null` would tell a client the field exists and is empty. The first five fields belong to
 * no jurisdiction and are always answered.
 *
 * READ-ONLY, AND NOT BY OMISSION - no method of the group writes bank details, the same way it writes no
 * address ({@see RequisiteAddressDto}). Nothing is filterable or sortable: the details are answered as
 * part of a requisite and are never the thing a read is addressed to.
 *
 * The country the row keeps is not published: it is written from the preset of the requisite in the first
 * place, and answering it here would offer a second answer to a question the preset already answers.
 * Neither is the identifier of the requisite - it is the one these details were read for.
 *
 * The full shape of bank details belongs to the group of methods of the bank details themselves; when
 * that group is published, this contract is the one it keeps rather than a second format beside it.
 */
final class RequisiteBankDetailDto extends Dto
{
	public int $id;

	/** The name of the record as its owner gave it; the name of the bank is {@see self::$bankName}. */
	public string $name;

	/** Whether the record is offered where bank details are chosen. */
	public bool $active;

	public int $sort;

	public string $comments;

	// Of a jurisdiction: answered only where the preset of the requisite stands for one that has it.

	public string $bankName;

	public string $bankAddress;

	public string $bankCode;

	public string $bankRoutingNumber;

	public string $agencyName;

	public string $bik;

	public string $mfo;

	public string $accountName;

	public string $accountNumber;

	public string $accountType;

	public string $accountCurrency;

	public string $correspondentAccountNumber;

	public string $iik;

	public string $iban;

	public string $swift;

	public string $bic;

	public string $codeB;

	public string $codeG;

	public string $rib;
}

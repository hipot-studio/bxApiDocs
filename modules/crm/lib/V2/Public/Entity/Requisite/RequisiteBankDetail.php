<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

/**
 * The bank details of a requisite - the account a counterparty is paid through, kept as a record of its
 * own. A requisite may have several of them, ordered as the card of the requisite orders them.
 *
 * Which of the bank fields the record has at all is decided by the jurisdiction of its requisite - the
 * country of the preset the requisite names - and not by the record: a Russian account is named by a
 * bank code and a correspondent account, a German one by a routing number and an IBAN, and neither
 * jurisdiction has a field for what the other asks. So a bank field carries two different absences and
 * a consumer must not confuse them:
 *
 * - `null` - the jurisdiction of the requisite has no such field, and it is not part of the record. A
 *   consumer leaves it out of what it builds rather than showing it empty;
 * - `''` - the field is part of the record and was left empty, which is a value like any other.
 *
 * The fields that belong to no jurisdiction - the name of the record, its comment, its activity and its
 * ordering - are always there and are never `null`.
 *
 * The country of the record is not published: it is written from the preset of the requisite in the
 * first place, and answering it here would offer a second answer to a question the preset already
 * answers. Neither is the identifier of the requisite: it is the one these details were read for.
 *
 * READ-ONLY, AND NOT BY OMISSION - no command of the group writes bank details, the same way it writes
 * no address ({@see RequisiteAddress}).
 */
final readonly class RequisiteBankDetail
{
	/**
	 * @param string $name the name of the record as its owner gave it - the name of the bank is
	 *        `bankName`, and the two are filled in independently.
	 * @param bool $active whether the record is offered where bank details are chosen; an inactive one
	 *        is still a record of its requisite.
	 * @param string|null $bik the bank code of the CIS jurisdictions, and `null` everywhere else - as
	 *        every field from here down is `null` outside the jurisdictions that have it.
	 */
	public function __construct(
		private int $id,
		private string $name,
		private bool $active,
		private int $sort,
		private string $comments,
		private ?string $bankName,
		private ?string $bankAddress,
		private ?string $bankCode,
		private ?string $bankRoutingNumber,
		private ?string $agencyName,
		private ?string $bik,
		private ?string $mfo,
		private ?string $accountName,
		private ?string $accountNumber,
		private ?string $accountType,
		private ?string $accountCurrency,
		private ?string $correspondentAccountNumber,
		private ?string $iik,
		private ?string $iban,
		private ?string $swift,
		private ?string $bic,
		private ?string $codeB,
		private ?string $codeG,
		private ?string $rib,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getActive(): bool
	{
		return $this->active;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function getComments(): string
	{
		return $this->comments;
	}

	public function getBankName(): ?string
	{
		return $this->bankName;
	}

	public function getBankAddress(): ?string
	{
		return $this->bankAddress;
	}

	public function getBankCode(): ?string
	{
		return $this->bankCode;
	}

	public function getBankRoutingNumber(): ?string
	{
		return $this->bankRoutingNumber;
	}

	public function getAgencyName(): ?string
	{
		return $this->agencyName;
	}

	public function getBik(): ?string
	{
		return $this->bik;
	}

	public function getMfo(): ?string
	{
		return $this->mfo;
	}

	public function getAccountName(): ?string
	{
		return $this->accountName;
	}

	public function getAccountNumber(): ?string
	{
		return $this->accountNumber;
	}

	public function getAccountType(): ?string
	{
		return $this->accountType;
	}

	public function getAccountCurrency(): ?string
	{
		return $this->accountCurrency;
	}

	public function getCorrespondentAccountNumber(): ?string
	{
		return $this->correspondentAccountNumber;
	}

	public function getIik(): ?string
	{
		return $this->iik;
	}

	public function getIban(): ?string
	{
		return $this->iban;
	}

	public function getSwift(): ?string
	{
		return $this->swift;
	}

	public function getBic(): ?string
	{
		return $this->bic;
	}

	public function getCodeB(): ?string
	{
		return $this->codeB;
	}

	public function getCodeG(): ?string
	{
		return $this->codeG;
	}

	public function getRib(): ?string
	{
		return $this->rib;
	}
}

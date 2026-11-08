<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

final class BankRequisite implements EntityInterface
{
	public function __construct(
		#[PositiveNumber(groups: ['update'])]
		private readonly ?int $id = null,
		#[PositiveNumber(groups: ['add'])]
		private readonly ?int $requisiteId = null,
		#[NotEmpty(groups: ['add', 'updateName'])]
		private readonly ?string $name = null,
		private readonly ?string $comments = null,
		private readonly ?string $bankName = null,
		private readonly ?string $bankAddress = null,
		private readonly ?string $bankCode = null,
		private readonly ?string $bankAgencyName = null,
		private readonly ?string $bankRoutingNumber = null,
		private readonly ?string $bik = null,
		private readonly ?string $mfo = null,
		private readonly ?string $bankAccountHolderName = null,
		private readonly ?string $bankAccountNumber = null,
		private readonly ?string $iik = null,
		private readonly ?string $bankAccountCurrency = null,
		private readonly ?string $correspondentAccountNumber = null,
		private readonly ?string $bankAccountType = null,
		private readonly ?string $iban = null,
		private readonly ?string $swift = null,
		private readonly ?string $bic = null,
		private readonly ?string $codeb = null,
		private readonly ?string $codeg = null,
		private readonly ?string $rib = null,
		private readonly ?BankRequisiteTemplate $template = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getRequisiteId(): ?int
	{
		return $this->requisiteId;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getComments(): ?string
	{
		return $this->comments;
	}

	public function getTemplate(): ?BankRequisiteTemplate
	{
		return $this->template;
	}

	public function getField(BankRequisiteField $field): ?string
	{
		return match ($field)
		{
			BankRequisiteField::BankName => $this->bankName,
			BankRequisiteField::BankAddress => $this->bankAddress,
			BankRequisiteField::BankCode => $this->bankCode,
			BankRequisiteField::BankAgencyName => $this->bankAgencyName,
			BankRequisiteField::BankRoutingNumber => $this->bankRoutingNumber,
			BankRequisiteField::Bik => $this->bik,
			BankRequisiteField::Mfo => $this->mfo,
			BankRequisiteField::BankAccountHolderName => $this->bankAccountHolderName,
			BankRequisiteField::BankAccountNumber => $this->bankAccountNumber,
			BankRequisiteField::Iik => $this->iik,
			BankRequisiteField::BankAccountCurrency => $this->bankAccountCurrency,
			BankRequisiteField::CorrespondentAccountNumber => $this->correspondentAccountNumber,
			BankRequisiteField::BankAccountType => $this->bankAccountType,
			BankRequisiteField::Iban => $this->iban,
			BankRequisiteField::Swift => $this->swift,
			BankRequisiteField::Bic => $this->bic,
			BankRequisiteField::Codeb => $this->codeb,
			BankRequisiteField::Codeg => $this->codeg,
			BankRequisiteField::Rib => $this->rib,
		};
	}

	public function withTemplate(BankRequisiteTemplate $template): self
	{
		return new self(...$this->constructorArguments(template: $template));
	}

	/** @return array<string, mixed> */
	private function constructorArguments(BankRequisiteTemplate $template): array
	{
		return [
			'id' => $this->id,
			'requisiteId' => $this->requisiteId,
			'name' => $this->name,
			'comments' => $this->comments,
			'bankName' => $this->bankName,
			'bankAddress' => $this->bankAddress,
			'bankCode' => $this->bankCode,
			'bankAgencyName' => $this->bankAgencyName,
			'bankRoutingNumber' => $this->bankRoutingNumber,
			'bik' => $this->bik,
			'mfo' => $this->mfo,
			'bankAccountHolderName' => $this->bankAccountHolderName,
			'bankAccountNumber' => $this->bankAccountNumber,
			'iik' => $this->iik,
			'bankAccountCurrency' => $this->bankAccountCurrency,
			'correspondentAccountNumber' => $this->correspondentAccountNumber,
			'bankAccountType' => $this->bankAccountType,
			'iban' => $this->iban,
			'swift' => $this->swift,
			'bic' => $this->bic,
			'codeb' => $this->codeb,
			'codeg' => $this->codeg,
			'rib' => $this->rib,
			'template' => $template,
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

enum BankRequisiteField: string
{
	case BankName = 'bankName';
	case BankAddress = 'bankAddress';
	case BankCode = 'bankCode';
	case BankAgencyName = 'bankAgencyName';
	case BankRoutingNumber = 'bankRoutingNumber';
	case Bik = 'bik';
	case Mfo = 'mfo';
	case BankAccountHolderName = 'bankAccountHolderName';
	case BankAccountNumber = 'bankAccountNumber';
	case Iik = 'iik';
	case BankAccountCurrency = 'bankAccountCurrency';
	case CorrespondentAccountNumber = 'correspondentAccountNumber';
	case BankAccountType = 'bankAccountType';
	case Iban = 'iban';
	case Swift = 'swift';
	case Bic = 'bic';
	case Codeb = 'codeb';
	case Codeg = 'codeg';
	case Rib = 'rib';

	/** @return self[] */
	public static function all(): array
	{
		return self::cases();
	}
}

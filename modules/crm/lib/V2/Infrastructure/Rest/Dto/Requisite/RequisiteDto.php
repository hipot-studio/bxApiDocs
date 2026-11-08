<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a requisite of a CRM contact or company, as the administrative read of the
 * whole portal publishes it.
 *
 * The list of properties is not a decision of this class: which column of `b_crm_requisite` is published,
 * under which name and with which of the two attributes was settled column by column when the method was
 * published, and the settlement is written down as constants in
 * {@see \Bitrix\Crm\Tests\Unit\Lib\V2\Infrastructure\Rest\Dto\Requisite\OwnerRequisiteDtoGeneratorTest},
 * where a case keeps this contract and the one of the per-owner routes naming the same fields. A field
 * added here on its own is a breach of that contract - a published field can never be removed.
 *
 * The property names are the field names of
 * {@see \Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite} one to one, so nothing is renamed between the
 * storage-facing read and the answer.
 *
 * No property carries {@see \Bitrix\Rest\V3\Attribute\Editable}: the only method behind this contract
 * reads. `Filterable` and `Sortable` are closed lists that start narrow - widening them later is
 * compatible, narrowing them is not. Only `id` and `ownerId` are sortable: every other column lacks an
 * index leading with it, and an ordering by one of them would order the whole table.
 *
 * `presetId`, `identificationTypeId` and `taxRegimeId` publish references to CRM dictionaries REST 3.0
 * offers no way to list yet. That is a known departure from the regulation rather than an omission.
 */
#[Title('CRM company or contact details')]
final class RequisiteDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	/** The contact or the company the record belongs to; no other owner is answered for. */
	#[Filterable]
	#[Sortable]
	public ?int $ownerId;

	/** @see \Bitrix\Crm\V2\Public\OwnerType only a contact or a company. */
	#[Filterable]
	public ?int $ownerTypeId;

	/** The preset of the requisite, which decides what the fields below mean and what they are called. */
	#[Filterable]
	public ?int $presetId;

	/** The name of the record itself, not of the person or the organization it holds the details of. */
	public ?string $name;

	#[Filterable]
	public ?bool $active;

	#[Filterable]
	public ?string $xmlId;

	/** The system the record came from, of use to an integration that exports the portal. */
	public ?string $originatorId;

	/** Published for parity with the legacy method; not sortable - no index leads with the column. */
	public ?int $sort;

	// Neither date is filterable, and the missing index is not the whole reason: a filter over an unindexed
	// column costs a scan of the whole table per request, which is the accepted price of a point lookup -
	// `xmlId` and `active` are filterable without an index - while a range of dates is walked page by page
	// and pays that scan again for every page. Opening them later together with an index is compatible.

	public ?DateTime $createdTime;

	public ?DateTime $updatedTime;

	public ?int $createdById;

	public ?int $updatedById;

	// Names and persons.

	/** The full name of an individual; `name` above belongs to the record. */
	#[Filterable]
	public ?string $fullName;

	public ?string $firstName;

	#[Filterable]
	public ?string $lastName;

	public ?string $secondName;

	#[Filterable]
	public ?string $companyTitle;

	public ?string $companyFullName;

	/** A free-form identifier of the organization, not a reference to a company of the CRM. */
	#[Filterable]
	public ?string $companyIdentifier;

	/** A date the storage keeps as a free-form string rather than as a moment in time. */
	public ?string $companyRegistrationDate;

	#[Filterable]
	public ?string $directorName;

	#[Filterable]
	public ?string $accountantName;

	#[Filterable]
	public ?string $ceoName;

	public ?string $ceoPosition;

	/** A contact person by name, not a reference to a contact of the CRM. */
	#[Filterable]
	public ?string $contactPerson;

	// Means of communication: one value each, not the multifield collections of a CRM item.

	#[Filterable]
	public ?string $emailAddress;

	#[Filterable]
	public ?string $phoneNumber;

	#[Filterable]
	public ?string $faxNumber;

	// The document that proves the identity of an individual.

	/** A value of the CRM status dictionary of identification kinds. */
	#[Filterable]
	public ?string $identificationTypeId;

	/** The kind of the document as free text, unlike {@see self::$identificationTypeId}. */
	public ?string $identityDocumentType;

	public ?string $identityDocumentSeries;

	#[Filterable]
	public ?string $identityDocumentNumber;

	#[Filterable]
	public ?string $identityDocumentPersonalNumber;

	/** @see self::$companyRegistrationDate for why a stored date is a string here. */
	public ?string $identityDocumentIssueDate;

	public ?string $identityDocumentIssuedBy;

	#[Filterable]
	public ?string $identityDocumentDepartmentCode;

	// Tax and registration numbers. One cell serves several jurisdictions, so the abbreviation of the
	// column is the name: a meaningful name would have to be chosen in favour of one country of many.

	/** The main tax number: INN, UNP, IPN, NIP, NIT, R.F.C., Steuernummer. */
	#[Filterable]
	public ?string $inn;

	#[Filterable]
	public ?string $kpp;

	/** Number in the trade register (Handelsregisternummer). */
	#[Filterable]
	public ?string $usrle;

	#[Filterable]
	public ?string $ifns;

	#[Filterable]
	public ?string $ogrn;

	#[Filterable]
	public ?string $ogrnip;

	#[Filterable]
	public ?string $okpo;

	#[Filterable]
	public ?string $oktmo;

	/** The code of the kind of activity: OKVED, OKED, code NAF (APE). */
	#[Filterable]
	public ?string $okved;

	#[Filterable]
	public ?string $edrpou;

	#[Filterable]
	public ?string $drfo;

	#[Filterable]
	public ?string $kbe;

	#[Filterable]
	public ?string $iin;

	#[Filterable]
	public ?string $bin;

	#[Filterable]
	public ?string $regon;

	#[Filterable]
	public ?string $krs;

	#[Filterable]
	public ?string $pesel;

	#[Filterable]
	public ?string $siret;

	#[Filterable]
	public ?string $siren;

	#[Filterable]
	public ?string $rcs;

	#[Filterable]
	public ?string $cnpj;

	#[Filterable]
	public ?string $cpf;

	/** State registration of a Brazilian taxpayer, unlike the certificate fields below. */
	#[Filterable]
	public ?string $stateRegistration;

	/** Municipal registration of a Brazilian taxpayer. */
	#[Filterable]
	public ?string $municipalRegistration;

	public ?string $legalForm;

	/** Capital social, kept as a string by the storage. */
	public ?string $shareCapital;

	/** A value of the CRM status dictionary of tax regimes. */
	public ?string $taxRegimeId;

	/** The country of residence as text, not a reference to a dictionary of countries. */
	#[Filterable]
	public ?string $residenceCountry;

	public ?string $basisDocument;

	// Certificates and VAT.

	public ?string $registrationCertificateSeries;

	#[Filterable]
	public ?string $registrationCertificateNumber;

	/** @see self::$companyRegistrationDate for why a stored date is a string here. */
	public ?string $registrationCertificateDate;

	#[Filterable]
	public ?bool $vatPayer;

	/** The VAT registration number (VAT ID, UmSt.-IdNr., VAT-EU), not a reference to a VAT rate. */
	#[Filterable]
	public ?string $vatNumber;

	public ?string $vatCertificateSeries;

	#[Filterable]
	public ?string $vatCertificateNumber;

	/** @see self::$companyRegistrationDate for why a stored date is a string here. */
	public ?string $vatCertificateDate;
}

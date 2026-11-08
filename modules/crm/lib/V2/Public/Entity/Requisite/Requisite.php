<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * A requisite of a CRM contact or company - the set of legal details a counterparty is billed and
 * contracted under, kept as a record of its own rather than as fields of its owner. It is what a
 * command of this domain is given and what a provider of it answers: a pure data object that neither
 * reads nor writes anything.
 *
 * One record carries the details of a single jurisdiction. The preset of the requisite ({@see Preset})
 * decides which of the fields below mean anything for it, what the interface calls them and in what
 * order it shows them, while the storage keeps a cell for every jurisdiction the product knows - so
 * most fields of any single requisite say nothing.
 *
 * THREE ABSENCES, AND THEY ARE NOT THE SAME. A field can be absent in three ways, and a consumer that
 * confuses them answers its own caller wrongly:
 *
 * - the model does not carry the field at all - a read that was not asked for it. Only
 *   {@see self::hasField()} says so; the getter of such a field answers `null` like any other;
 * - the model carries the field and its value is `null` - a cell that is empty, or, in a write, a cell
 *   the caller is emptying on purpose;
 * - the field is not part of the record at all, because the preset of the requisite does not show it.
 *   That is a question about the preset and is asked of the preset.
 *
 * WHAT A WRITE CARRIES. Every setter marks its field as changed, and a write carries the marked fields
 * and only them, so a field left alone keeps its stored value. A marked field carries its value as it
 * is: `null` on a marked field empties the cell rather than dropping out of the write, because the mark
 * - and not the value - is what says the caller named the field. This is where the group differs from
 * the category domain, where a `null` reads as "not given": there the mark and the value say the same
 * thing, here they do not, and the difference is the whole reason a client can empty a legal detail.
 *
 * `id`, `ownerId` and `ownerTypeId` get no change mark: they address the record rather than describe
 * it. Setting them says which requisite is meant and whom a new one is created for, never that either
 * should change - the owner of an existing requisite is fixed, and a write is addressed by the argument
 * of its command. It is the treatment the model of a pipeline gives its own address
 * ({@see \Bitrix\Crm\V2\Public\Entity\Category\Category}).
 *
 * `createdTime`, `updatedTime`, `createdById` and `updatedById` have no setter at all: a read fills
 * them and no write of the group accepts them.
 *
 * The user fields a preset may show are not part of this model - the group does not answer for them -
 * and neither is the internal write mode of an address-only requisite, which is the shape of a write
 * rather than a property of a record.
 *
 * The methods marked `@internal` carry the marks and the values over to the commands and the providers
 * of the domain. They are public because those live in other namespaces and PHP offers nothing
 * narrower, not because they are part of the contract; the ones a consumer has a use for are
 * {@see self::hasField()} and {@see self::hasChangedFields()}.
 */
final class Requisite implements EntityInterface
{
	public const id = 'id';
	public const ownerId = 'ownerId';
	public const ownerTypeId = 'ownerTypeId';
	public const presetId = 'presetId';
	public const name = 'name';
	public const active = 'active';
	public const xmlId = 'xmlId';
	public const originatorId = 'originatorId';
	public const sort = 'sort';
	public const createdTime = 'createdTime';
	public const updatedTime = 'updatedTime';
	public const createdById = 'createdById';
	public const updatedById = 'updatedById';

	public const fullName = 'fullName';
	public const firstName = 'firstName';
	public const lastName = 'lastName';
	public const secondName = 'secondName';
	public const companyTitle = 'companyTitle';
	public const companyFullName = 'companyFullName';
	public const companyIdentifier = 'companyIdentifier';
	public const companyRegistrationDate = 'companyRegistrationDate';
	public const directorName = 'directorName';
	public const accountantName = 'accountantName';
	public const ceoName = 'ceoName';
	public const ceoPosition = 'ceoPosition';
	public const contactPerson = 'contactPerson';

	public const emailAddress = 'emailAddress';
	public const phoneNumber = 'phoneNumber';
	public const faxNumber = 'faxNumber';

	public const identificationTypeId = 'identificationTypeId';
	public const identityDocumentType = 'identityDocumentType';
	public const identityDocumentSeries = 'identityDocumentSeries';
	public const identityDocumentNumber = 'identityDocumentNumber';
	public const identityDocumentPersonalNumber = 'identityDocumentPersonalNumber';
	public const identityDocumentIssueDate = 'identityDocumentIssueDate';
	public const identityDocumentIssuedBy = 'identityDocumentIssuedBy';
	public const identityDocumentDepartmentCode = 'identityDocumentDepartmentCode';

	public const inn = 'inn';
	public const kpp = 'kpp';
	public const usrle = 'usrle';
	public const ifns = 'ifns';
	public const ogrn = 'ogrn';
	public const ogrnip = 'ogrnip';
	public const okpo = 'okpo';
	public const oktmo = 'oktmo';
	public const okved = 'okved';
	public const edrpou = 'edrpou';
	public const drfo = 'drfo';
	public const kbe = 'kbe';
	public const iin = 'iin';
	public const bin = 'bin';
	public const regon = 'regon';
	public const krs = 'krs';
	public const pesel = 'pesel';
	public const siret = 'siret';
	public const siren = 'siren';
	public const rcs = 'rcs';
	public const cnpj = 'cnpj';
	public const cpf = 'cpf';
	public const stateRegistration = 'stateRegistration';
	public const municipalRegistration = 'municipalRegistration';
	public const legalForm = 'legalForm';
	public const shareCapital = 'shareCapital';
	public const taxRegimeId = 'taxRegimeId';
	public const residenceCountry = 'residenceCountry';
	public const basisDocument = 'basisDocument';

	public const registrationCertificateSeries = 'registrationCertificateSeries';
	public const registrationCertificateNumber = 'registrationCertificateNumber';
	public const registrationCertificateDate = 'registrationCertificateDate';
	public const vatPayer = 'vatPayer';
	public const vatNumber = 'vatNumber';
	public const vatCertificateSeries = 'vatCertificateSeries';
	public const vatCertificateNumber = 'vatCertificateNumber';
	public const vatCertificateDate = 'vatCertificateDate';

	/** The addresses of the requisite, {@see RequisiteAddress}: records of their own, not a field. */
	public const addresses = 'addresses';

	/** The bank details of the requisite, {@see RequisiteBankDetail}: the same. */
	public const bankRequisites = 'bankRequisites';

	/**
	 * Every field of a requisite, in the order the contract lists them. The two relations above are not
	 * among them - they are records of their own, read on request and never carried by a write.
	 */
	private const FIELDS = [
		self::id,
		self::ownerId,
		self::ownerTypeId,
		self::presetId,
		self::name,
		self::active,
		self::xmlId,
		self::originatorId,
		self::sort,
		self::createdTime,
		self::updatedTime,
		self::createdById,
		self::updatedById,

		self::fullName,
		self::firstName,
		self::lastName,
		self::secondName,
		self::companyTitle,
		self::companyFullName,
		self::companyIdentifier,
		self::companyRegistrationDate,
		self::directorName,
		self::accountantName,
		self::ceoName,
		self::ceoPosition,
		self::contactPerson,

		self::emailAddress,
		self::phoneNumber,
		self::faxNumber,

		self::identificationTypeId,
		self::identityDocumentType,
		self::identityDocumentSeries,
		self::identityDocumentNumber,
		self::identityDocumentPersonalNumber,
		self::identityDocumentIssueDate,
		self::identityDocumentIssuedBy,
		self::identityDocumentDepartmentCode,

		self::inn,
		self::kpp,
		self::usrle,
		self::ifns,
		self::ogrn,
		self::ogrnip,
		self::okpo,
		self::oktmo,
		self::okved,
		self::edrpou,
		self::drfo,
		self::kbe,
		self::iin,
		self::bin,
		self::regon,
		self::krs,
		self::pesel,
		self::siret,
		self::siren,
		self::rcs,
		self::cnpj,
		self::cpf,
		self::stateRegistration,
		self::municipalRegistration,
		self::legalForm,
		self::shareCapital,
		self::taxRegimeId,
		self::residenceCountry,
		self::basisDocument,

		self::registrationCertificateSeries,
		self::registrationCertificateNumber,
		self::registrationCertificateDate,
		self::vatPayer,
		self::vatNumber,
		self::vatCertificateSeries,
		self::vatCertificateNumber,
		self::vatCertificateDate,
	];

	/** @var array<string, int|bool|string|DateTime|null> the fields the model carries, by name. */
	private array $values = [];

	/** @var array<string, true> */
	private array $changedFields = [];

	/** @var RequisiteAddress[]|null */
	private ?array $addresses = null;

	/** @var RequisiteBankDetail[]|null */
	private ?array $bankRequisites = null;

	/** @var array<string, int>|null */
	private static ?array $fieldIndex = null;

	public function getId(): ?int
	{
		return $this->values[self::id] ?? null;
	}

	public function setId(?int $id): self
	{
		return $this->setAddressField(self::id, $id);
	}

	/**
	 * The record the requisite belongs to. Part of the address of the requisite rather than of its
	 * content: a creation is told which owner it is for, and an owner set on an existing requisite is
	 * never a request to move it.
	 */
	public function getOwnerId(): ?int
	{
		return $this->values[self::ownerId] ?? null;
	}

	public function setOwnerId(?int $ownerId): self
	{
		return $this->setAddressField(self::ownerId, $ownerId);
	}

	/**
	 * The type of the owner - a contact or a company, and no other type of the CRM has requisites. Part
	 * of the address of the requisite, as `ownerId` is.
	 */
	public function getOwnerTypeId(): ?int
	{
		return $this->values[self::ownerTypeId] ?? null;
	}

	public function setOwnerTypeId(?int $ownerTypeId): self
	{
		return $this->setAddressField(self::ownerTypeId, $ownerTypeId);
	}

	public function getPresetId(): ?int
	{
		return $this->values[self::presetId] ?? null;
	}

	public function setPresetId(?int $presetId): self
	{
		return $this->setField(self::presetId, $presetId);
	}

	public function getName(): ?string
	{
		return $this->values[self::name] ?? null;
	}

	public function setName(?string $name): self
	{
		return $this->setField(self::name, $name);
	}

	/** Whether the requisite is offered where a requisite of its owner is chosen; a new one is active. */
	public function getActive(): ?bool
	{
		return $this->values[self::active] ?? null;
	}

	public function setActive(?bool $active): self
	{
		return $this->setField(self::active, $active);
	}

	public function getXmlId(): ?string
	{
		return $this->values[self::xmlId] ?? null;
	}

	public function setXmlId(?string $xmlId): self
	{
		return $this->setField(self::xmlId, $xmlId);
	}

	public function getOriginatorId(): ?string
	{
		return $this->values[self::originatorId] ?? null;
	}

	public function setOriginatorId(?string $originatorId): self
	{
		return $this->setField(self::originatorId, $originatorId);
	}

	public function getSort(): ?int
	{
		return $this->values[self::sort] ?? null;
	}

	public function setSort(?int $sort): self
	{
		return $this->setField(self::sort, $sort);
	}

	/**
	 * When the requisite was created. Read-only: filled by a read, and refused by every write of the
	 * group - as `updatedTime`, `createdById` and `updatedById` are.
	 */
	public function getCreatedTime(): ?DateTime
	{
		return $this->values[self::createdTime] ?? null;
	}

	public function getUpdatedTime(): ?DateTime
	{
		return $this->values[self::updatedTime] ?? null;
	}

	public function getCreatedById(): ?int
	{
		return $this->values[self::createdById] ?? null;
	}

	public function getUpdatedById(): ?int
	{
		return $this->values[self::updatedById] ?? null;
	}

	public function getFullName(): ?string
	{
		return $this->values[self::fullName] ?? null;
	}

	public function setFullName(?string $fullName): self
	{
		return $this->setField(self::fullName, $fullName);
	}

	public function getFirstName(): ?string
	{
		return $this->values[self::firstName] ?? null;
	}

	public function setFirstName(?string $firstName): self
	{
		return $this->setField(self::firstName, $firstName);
	}

	public function getLastName(): ?string
	{
		return $this->values[self::lastName] ?? null;
	}

	public function setLastName(?string $lastName): self
	{
		return $this->setField(self::lastName, $lastName);
	}

	public function getSecondName(): ?string
	{
		return $this->values[self::secondName] ?? null;
	}

	public function setSecondName(?string $secondName): self
	{
		return $this->setField(self::secondName, $secondName);
	}

	public function getCompanyTitle(): ?string
	{
		return $this->values[self::companyTitle] ?? null;
	}

	public function setCompanyTitle(?string $companyTitle): self
	{
		return $this->setField(self::companyTitle, $companyTitle);
	}

	public function getCompanyFullName(): ?string
	{
		return $this->values[self::companyFullName] ?? null;
	}

	public function setCompanyFullName(?string $companyFullName): self
	{
		return $this->setField(self::companyFullName, $companyFullName);
	}

	/** A free-form identifier of the organization, not a reference to a company of the CRM. */
	public function getCompanyIdentifier(): ?string
	{
		return $this->values[self::companyIdentifier] ?? null;
	}

	public function setCompanyIdentifier(?string $companyIdentifier): self
	{
		return $this->setField(self::companyIdentifier, $companyIdentifier);
	}

	/**
	 * A date the group keeps as a free-form string rather than as a moment in time, as
	 * `identityDocumentIssueDate`, `registrationCertificateDate` and `vatCertificateDate` are too: the
	 * value is neither parsed on the way in nor formatted on the way out.
	 */
	public function getCompanyRegistrationDate(): ?string
	{
		return $this->values[self::companyRegistrationDate] ?? null;
	}

	public function setCompanyRegistrationDate(?string $companyRegistrationDate): self
	{
		return $this->setField(self::companyRegistrationDate, $companyRegistrationDate);
	}

	public function getDirectorName(): ?string
	{
		return $this->values[self::directorName] ?? null;
	}

	public function setDirectorName(?string $directorName): self
	{
		return $this->setField(self::directorName, $directorName);
	}

	public function getAccountantName(): ?string
	{
		return $this->values[self::accountantName] ?? null;
	}

	public function setAccountantName(?string $accountantName): self
	{
		return $this->setField(self::accountantName, $accountantName);
	}

	public function getCeoName(): ?string
	{
		return $this->values[self::ceoName] ?? null;
	}

	public function setCeoName(?string $ceoName): self
	{
		return $this->setField(self::ceoName, $ceoName);
	}

	public function getCeoPosition(): ?string
	{
		return $this->values[self::ceoPosition] ?? null;
	}

	public function setCeoPosition(?string $ceoPosition): self
	{
		return $this->setField(self::ceoPosition, $ceoPosition);
	}

	/** A contact person as a line of text, not a reference to a contact of the CRM. */
	public function getContactPerson(): ?string
	{
		return $this->values[self::contactPerson] ?? null;
	}

	public function setContactPerson(?string $contactPerson): self
	{
		return $this->setField(self::contactPerson, $contactPerson);
	}

	public function getEmailAddress(): ?string
	{
		return $this->values[self::emailAddress] ?? null;
	}

	public function setEmailAddress(?string $emailAddress): self
	{
		return $this->setField(self::emailAddress, $emailAddress);
	}

	public function getPhoneNumber(): ?string
	{
		return $this->values[self::phoneNumber] ?? null;
	}

	public function setPhoneNumber(?string $phoneNumber): self
	{
		return $this->setField(self::phoneNumber, $phoneNumber);
	}

	public function getFaxNumber(): ?string
	{
		return $this->values[self::faxNumber] ?? null;
	}

	public function setFaxNumber(?string $faxNumber): self
	{
		return $this->setField(self::faxNumber, $faxNumber);
	}

	/** A value of a status dictionary of the CRM, kept as the string that dictionary names it by. */
	public function getIdentificationTypeId(): ?string
	{
		return $this->values[self::identificationTypeId] ?? null;
	}

	public function setIdentificationTypeId(?string $identificationTypeId): self
	{
		return $this->setField(self::identificationTypeId, $identificationTypeId);
	}

	public function getIdentityDocumentType(): ?string
	{
		return $this->values[self::identityDocumentType] ?? null;
	}

	public function setIdentityDocumentType(?string $identityDocumentType): self
	{
		return $this->setField(self::identityDocumentType, $identityDocumentType);
	}

	public function getIdentityDocumentSeries(): ?string
	{
		return $this->values[self::identityDocumentSeries] ?? null;
	}

	public function setIdentityDocumentSeries(?string $identityDocumentSeries): self
	{
		return $this->setField(self::identityDocumentSeries, $identityDocumentSeries);
	}

	public function getIdentityDocumentNumber(): ?string
	{
		return $this->values[self::identityDocumentNumber] ?? null;
	}

	public function setIdentityDocumentNumber(?string $identityDocumentNumber): self
	{
		return $this->setField(self::identityDocumentNumber, $identityDocumentNumber);
	}

	public function getIdentityDocumentPersonalNumber(): ?string
	{
		return $this->values[self::identityDocumentPersonalNumber] ?? null;
	}

	public function setIdentityDocumentPersonalNumber(?string $identityDocumentPersonalNumber): self
	{
		return $this->setField(self::identityDocumentPersonalNumber, $identityDocumentPersonalNumber);
	}

	public function getIdentityDocumentIssueDate(): ?string
	{
		return $this->values[self::identityDocumentIssueDate] ?? null;
	}

	public function setIdentityDocumentIssueDate(?string $identityDocumentIssueDate): self
	{
		return $this->setField(self::identityDocumentIssueDate, $identityDocumentIssueDate);
	}

	public function getIdentityDocumentIssuedBy(): ?string
	{
		return $this->values[self::identityDocumentIssuedBy] ?? null;
	}

	public function setIdentityDocumentIssuedBy(?string $identityDocumentIssuedBy): self
	{
		return $this->setField(self::identityDocumentIssuedBy, $identityDocumentIssuedBy);
	}

	public function getIdentityDocumentDepartmentCode(): ?string
	{
		return $this->values[self::identityDocumentDepartmentCode] ?? null;
	}

	public function setIdentityDocumentDepartmentCode(?string $identityDocumentDepartmentCode): self
	{
		return $this->setField(self::identityDocumentDepartmentCode, $identityDocumentDepartmentCode);
	}

	public function getInn(): ?string
	{
		return $this->values[self::inn] ?? null;
	}

	public function setInn(?string $inn): self
	{
		return $this->setField(self::inn, $inn);
	}

	public function getKpp(): ?string
	{
		return $this->values[self::kpp] ?? null;
	}

	public function setKpp(?string $kpp): self
	{
		return $this->setField(self::kpp, $kpp);
	}

	public function getUsrle(): ?string
	{
		return $this->values[self::usrle] ?? null;
	}

	public function setUsrle(?string $usrle): self
	{
		return $this->setField(self::usrle, $usrle);
	}

	public function getIfns(): ?string
	{
		return $this->values[self::ifns] ?? null;
	}

	public function setIfns(?string $ifns): self
	{
		return $this->setField(self::ifns, $ifns);
	}

	public function getOgrn(): ?string
	{
		return $this->values[self::ogrn] ?? null;
	}

	public function setOgrn(?string $ogrn): self
	{
		return $this->setField(self::ogrn, $ogrn);
	}

	public function getOgrnip(): ?string
	{
		return $this->values[self::ogrnip] ?? null;
	}

	public function setOgrnip(?string $ogrnip): self
	{
		return $this->setField(self::ogrnip, $ogrnip);
	}

	public function getOkpo(): ?string
	{
		return $this->values[self::okpo] ?? null;
	}

	public function setOkpo(?string $okpo): self
	{
		return $this->setField(self::okpo, $okpo);
	}

	public function getOktmo(): ?string
	{
		return $this->values[self::oktmo] ?? null;
	}

	public function setOktmo(?string $oktmo): self
	{
		return $this->setField(self::oktmo, $oktmo);
	}

	public function getOkved(): ?string
	{
		return $this->values[self::okved] ?? null;
	}

	public function setOkved(?string $okved): self
	{
		return $this->setField(self::okved, $okved);
	}

	public function getEdrpou(): ?string
	{
		return $this->values[self::edrpou] ?? null;
	}

	public function setEdrpou(?string $edrpou): self
	{
		return $this->setField(self::edrpou, $edrpou);
	}

	public function getDrfo(): ?string
	{
		return $this->values[self::drfo] ?? null;
	}

	public function setDrfo(?string $drfo): self
	{
		return $this->setField(self::drfo, $drfo);
	}

	public function getKbe(): ?string
	{
		return $this->values[self::kbe] ?? null;
	}

	public function setKbe(?string $kbe): self
	{
		return $this->setField(self::kbe, $kbe);
	}

	public function getIin(): ?string
	{
		return $this->values[self::iin] ?? null;
	}

	public function setIin(?string $iin): self
	{
		return $this->setField(self::iin, $iin);
	}

	public function getBin(): ?string
	{
		return $this->values[self::bin] ?? null;
	}

	public function setBin(?string $bin): self
	{
		return $this->setField(self::bin, $bin);
	}

	public function getRegon(): ?string
	{
		return $this->values[self::regon] ?? null;
	}

	public function setRegon(?string $regon): self
	{
		return $this->setField(self::regon, $regon);
	}

	public function getKrs(): ?string
	{
		return $this->values[self::krs] ?? null;
	}

	public function setKrs(?string $krs): self
	{
		return $this->setField(self::krs, $krs);
	}

	public function getPesel(): ?string
	{
		return $this->values[self::pesel] ?? null;
	}

	public function setPesel(?string $pesel): self
	{
		return $this->setField(self::pesel, $pesel);
	}

	public function getSiret(): ?string
	{
		return $this->values[self::siret] ?? null;
	}

	public function setSiret(?string $siret): self
	{
		return $this->setField(self::siret, $siret);
	}

	public function getSiren(): ?string
	{
		return $this->values[self::siren] ?? null;
	}

	public function setSiren(?string $siren): self
	{
		return $this->setField(self::siren, $siren);
	}

	public function getRcs(): ?string
	{
		return $this->values[self::rcs] ?? null;
	}

	public function setRcs(?string $rcs): self
	{
		return $this->setField(self::rcs, $rcs);
	}

	public function getCnpj(): ?string
	{
		return $this->values[self::cnpj] ?? null;
	}

	public function setCnpj(?string $cnpj): self
	{
		return $this->setField(self::cnpj, $cnpj);
	}

	public function getCpf(): ?string
	{
		return $this->values[self::cpf] ?? null;
	}

	public function setCpf(?string $cpf): self
	{
		return $this->setField(self::cpf, $cpf);
	}

	public function getStateRegistration(): ?string
	{
		return $this->values[self::stateRegistration] ?? null;
	}

	public function setStateRegistration(?string $stateRegistration): self
	{
		return $this->setField(self::stateRegistration, $stateRegistration);
	}

	public function getMunicipalRegistration(): ?string
	{
		return $this->values[self::municipalRegistration] ?? null;
	}

	public function setMunicipalRegistration(?string $municipalRegistration): self
	{
		return $this->setField(self::municipalRegistration, $municipalRegistration);
	}

	public function getLegalForm(): ?string
	{
		return $this->values[self::legalForm] ?? null;
	}

	public function setLegalForm(?string $legalForm): self
	{
		return $this->setField(self::legalForm, $legalForm);
	}

	public function getShareCapital(): ?string
	{
		return $this->values[self::shareCapital] ?? null;
	}

	public function setShareCapital(?string $shareCapital): self
	{
		return $this->setField(self::shareCapital, $shareCapital);
	}

	public function getTaxRegimeId(): ?string
	{
		return $this->values[self::taxRegimeId] ?? null;
	}

	public function setTaxRegimeId(?string $taxRegimeId): self
	{
		return $this->setField(self::taxRegimeId, $taxRegimeId);
	}

	public function getResidenceCountry(): ?string
	{
		return $this->values[self::residenceCountry] ?? null;
	}

	public function setResidenceCountry(?string $residenceCountry): self
	{
		return $this->setField(self::residenceCountry, $residenceCountry);
	}

	public function getBasisDocument(): ?string
	{
		return $this->values[self::basisDocument] ?? null;
	}

	public function setBasisDocument(?string $basisDocument): self
	{
		return $this->setField(self::basisDocument, $basisDocument);
	}

	public function getRegistrationCertificateSeries(): ?string
	{
		return $this->values[self::registrationCertificateSeries] ?? null;
	}

	public function setRegistrationCertificateSeries(?string $registrationCertificateSeries): self
	{
		return $this->setField(self::registrationCertificateSeries, $registrationCertificateSeries);
	}

	public function getRegistrationCertificateNumber(): ?string
	{
		return $this->values[self::registrationCertificateNumber] ?? null;
	}

	public function setRegistrationCertificateNumber(?string $registrationCertificateNumber): self
	{
		return $this->setField(self::registrationCertificateNumber, $registrationCertificateNumber);
	}

	public function getRegistrationCertificateDate(): ?string
	{
		return $this->values[self::registrationCertificateDate] ?? null;
	}

	public function setRegistrationCertificateDate(?string $registrationCertificateDate): self
	{
		return $this->setField(self::registrationCertificateDate, $registrationCertificateDate);
	}

	/** Whether the counterparty pays value added tax; a requisite that does not say is not a payer. */
	public function getVatPayer(): ?bool
	{
		return $this->values[self::vatPayer] ?? null;
	}

	public function setVatPayer(?bool $vatPayer): self
	{
		return $this->setField(self::vatPayer, $vatPayer);
	}

	public function getVatNumber(): ?string
	{
		return $this->values[self::vatNumber] ?? null;
	}

	public function setVatNumber(?string $vatNumber): self
	{
		return $this->setField(self::vatNumber, $vatNumber);
	}

	public function getVatCertificateSeries(): ?string
	{
		return $this->values[self::vatCertificateSeries] ?? null;
	}

	public function setVatCertificateSeries(?string $vatCertificateSeries): self
	{
		return $this->setField(self::vatCertificateSeries, $vatCertificateSeries);
	}

	public function getVatCertificateNumber(): ?string
	{
		return $this->values[self::vatCertificateNumber] ?? null;
	}

	public function setVatCertificateNumber(?string $vatCertificateNumber): self
	{
		return $this->setField(self::vatCertificateNumber, $vatCertificateNumber);
	}

	public function getVatCertificateDate(): ?string
	{
		return $this->values[self::vatCertificateDate] ?? null;
	}

	public function setVatCertificateDate(?string $vatCertificateDate): self
	{
		return $this->setField(self::vatCertificateDate, $vatCertificateDate);
	}

	/**
	 * The addresses of the requisite, and `null` when the read was not asked for them. A requisite that
	 * has none answers `[]`: a consumer that cannot tell the two apart would report "no addresses" to a
	 * client that never asked about addresses.
	 *
	 * @return RequisiteAddress[]|null in the order the read fixed - by the type of the address.
	 */
	public function getAddresses(): ?array
	{
		return $this->addresses;
	}

	/**
	 * @internal Filled by a read. The addresses of a requisite are read-only in this group - no command
	 * of it writes them - so this is not a way to change them.
	 */
	public function setAddresses(RequisiteAddress ...$addresses): self
	{
		$this->addresses = $addresses;

		return $this;
	}

	/**
	 * The bank details of the requisite, `null` and `[]` reading the same way they do in
	 * {@see self::getAddresses()}.
	 *
	 * @return RequisiteBankDetail[]|null in the order the read fixed - the one the card of the requisite
	 *         shows them in.
	 */
	public function getBankRequisites(): ?array
	{
		return $this->bankRequisites;
	}

	/**
	 * @internal Filled by a read, {@see self::setAddresses()}.
	 */
	public function setBankRequisites(RequisiteBankDetail ...$bankRequisites): self
	{
		$this->bankRequisites = $bankRequisites;

		return $this;
	}

	/**
	 * Whether the model carries an answer for $field. A getter cannot say it: a field a read was not
	 * asked for and a field whose cell is empty both answer `null`.
	 *
	 * The relations are answered for as well - `hasField(self::addresses)` says whether the addresses
	 * were read at all.
	 */
	public function hasField(string $field): bool
	{
		if ($field === self::addresses)
		{
			return $this->addresses !== null;
		}

		if ($field === self::bankRequisites)
		{
			return $this->bankRequisites !== null;
		}

		return array_key_exists($field, $this->values);
	}

	/**
	 * Whether a write of this requisite would carry anything at all. An update given a requisite that
	 * carries nothing changes nothing - which is a successful operation and not a refusal.
	 */
	public function hasChangedFields(): bool
	{
		return $this->changedFields !== [];
	}

	/**
	 * @internal The fields a write is to carry and their values - the ones a setter was called on, in
	 * the order they were set. A `null` among them is a cell to empty rather than a field to leave
	 * alone: what says the caller named a field is the mark, not the value.
	 *
	 * @return array<string, int|bool|string|DateTime|null>
	 */
	public function getChangedFieldValues(): array
	{
		return array_intersect_key($this->values, $this->changedFields);
	}

	/**
	 * @internal Every field the model carries and its value - what a read filled, or what a write was
	 * given. The relations are not among them; they have their own getters.
	 *
	 * @return array<string, int|bool|string|DateTime|null>
	 */
	public function getFieldValues(): array
	{
		return $this->values;
	}

	/**
	 * @internal Used by a provider once it has filled a requisite it read, and by a command once the
	 * write it reports has gone through: a requisite that comes from the domain carries no changes of
	 * its own, so the same object can be handed to the next write without repeating this one.
	 */
	public function resetChangedFields(): void
	{
		$this->changedFields = [];
	}

	/**
	 * @internal Fills $field without marking it changed - the way a read fills a requisite, its
	 * read-only fields included. Not a way to build a write: a field set through here is carried by
	 * none.
	 *
	 * @throws ArgumentException a name that is no field of a requisite. Dropping it silently would
	 *         leave a read answering a requisite without a field its caller believes it asked for.
	 */
	public function internalSet(string $field, int|bool|string|DateTime|null $value): self
	{
		self::$fieldIndex ??= array_flip(self::FIELDS);

		if (!isset(self::$fieldIndex[$field]))
		{
			throw new ArgumentException(sprintf('Unknown requisite field `%s`', $field), 'field');
		}

		$this->values[$field] = $value;

		return $this;
	}

	private function setField(string $field, int|bool|string|DateTime|null $value): self
	{
		$this->values[$field] = $value;
		$this->changedFields[$field] = true;

		return $this;
	}

	/**
	 * A field that addresses the record rather than describing it: it is kept and it is answered for,
	 * and no write ever carries it.
	 */
	private function setAddressField(string $field, ?int $value): self
	{
		$this->values[$field] = $value;

		return $this;
	}
}

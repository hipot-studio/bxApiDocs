<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * The external contract of a requisite of a CRM contact or of a CRM company, as the five methods of the
 * group read and write it.
 *
 * Declared here is the whole of the contract; the DTO a route actually answers with is built out of this
 * one per entity type by {@see OwnerRequisiteDtoGenerator}, which is also the only place the owner field
 * gets the name of the entity of the route - `contactId` or `companyId`. The name of the owner is asked of
 * {@see self::ownerFieldName()} rather than spelled out again by each consumer.
 *
 * A SECOND CONTRACT ON THE SAME RECORD, AND KNOWINGLY SO. The administrative read of the whole portal
 * answers with {@see RequisiteDto}, which this class neither extends nor replaces: that one publishes no
 * writable field, names the owner by the pair "identifier and type", and its own docblock forbids adding
 * fields to it. The two share one vocabulary - the field names of
 * {@see \Bitrix\Crm\V2\Public\Entity\Requisite\Requisite}, which are the field names of the storage-facing
 * read one to one - so a field means the same thing in both, and a test keeps their reading composition in
 * step.
 *
 * WHAT MAY BE WRITTEN IS NOT DECIDED HERE. The composition of writable fields, the groups each of them is
 * writable in, and what is required at creation were settled field by field when the group was published.
 * That settlement is written down as constants in
 * {@see \Bitrix\Crm\Tests\Unit\Lib\V2\Infrastructure\Rest\Dto\Requisite\OwnerRequisiteDtoGeneratorTest},
 * which restates it by hand rather than reading it out of this declaration - so the two disagreeing is
 * what a case there goes red on. An `#[Editable]` added here on its own is a breach of that contract: a
 * published field can never be taken back.
 *
 * NO LENGTH RULE, AND NOT BY OMISSION. Every writable text field of a requisite is bounded, and the bounds
 * live in the length map of the portal, which
 * {@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteFieldValidator} reads as it is. A copy of those
 * sixty-three numbers here would be a second list to keep in step with the map, and it would answer a
 * client worse: the domain refuses a long value naming the field and the limit it broke, and a rule here
 * would fire first with neither.
 *
 * ONE FILTERABLE FIELD, AND THE LIST IS MEANT TO BE READ THAT WAY. A page of this group is the requisites
 * of one owner, and a contact or a company keeps a handful of them; every read here already names the
 * owner, so the whole of what a filter of this group narrows is that page of three rows. Publishing the
 * forty-nine columns of the administrative read as conditions over it would mean giving the public read of
 * CRM V2 a way to express a condition at all - a published type covering every operator over every field -
 * and it would be built for a page nothing needs narrowed. So the owner is the one field marked
 * {@see Filterable}, and a condition over any other is refused by the framework as a field that was never
 * declared filterable. The list may be added to later without breaking a client: a filter that was refused
 * and starts being accepted takes nothing away, which is why the narrow list is the one to publish first.
 * The administrative read of the whole portal ({@see RequisiteDto}) is untouched by this and keeps its
 * wide filter - it is a page of the portal rather than of one owner, and narrowing it is what a client
 * has to do.
 *
 * THE TRAP OF A FIELD BOTH REQUIRED AND WRITABLE IN ONE GROUP ONLY. A field declared required in any group
 * never reaches the check of what may be written
 * ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}), so the framework will not refuse an owner
 * sent to an update on its own. The refusal is the controller's to make, by the same means the controller
 * of categories makes it. `presetId` is not in that position - it is writable in both groups.
 *
 * The meaning of each subject field below is the one {@see RequisiteDto} states for it, field for field;
 * it is not restated here so that the two contracts cannot come to describe the same column differently.
 */
abstract class AbstractOwnerRequisiteDto extends Dto
{
	/** The name the contract of a contact route gives the owner of a requisite. */
	public const OWNER_FIELD_OF_CONTACT = 'contactId';

	/** The name the contract of a company route gives the owner of a requisite. */
	public const OWNER_FIELD_OF_COMPANY = 'companyId';

	/**
	 * The name the owner is published under on a route of this entity type. The property below is named
	 * as the domain names it, and this is where that name becomes the one of the route - the generator,
	 * the mappers of the group and its controllers all ask here rather than each keeping a map of two
	 * names.
	 *
	 * @throws ArgumentException for a type no requisite may belong to. A requisite belongs to a contact
	 *         or to a company and to nothing else, and a route of any other entity is a mistake in the
	 *         declaration of the route rather than a request that could be answered.
	 */
	public static function ownerFieldName(EntityType $entityType): string
	{
		return match ($entityType->getId())
		{
			OwnerType::CONTACT => self::OWNER_FIELD_OF_CONTACT,
			OwnerType::COMPANY => self::OWNER_FIELD_OF_COMPANY,
			default => throw new ArgumentException(
				'A requisite belongs to a contact or to a company: ' . $entityType->getId(),
			),
		};
	}

	#[Sortable]
	public int $id;

	/**
	 * The record the requisite belongs to, published under the name of the entity of the route
	 * ({@see self::ownerFieldName()}); `ownerId` is the name of the domain and no route answers with it.
	 *
	 * Named once, when the requisite is created: moving a requisite between owners is not on offer, which
	 * is why the field is writable in the creation alone. An owner that is not there is answered as
	 * absence rather than as a refusal of the form, so no rule of a positive number stands here - it would
	 * turn one of the two answers into the other.
	 */
	#[Editable(['add'])]
	#[Required(['add'])]
	#[Filterable]
	public int $ownerId;

	/**
	 * The preset of the requisite, which decides what the subject fields below mean and which of them the
	 * requisite has at all. Writable in a change as well: a requisite changes preset the way its card
	 * changes it, and the values outside the new preset are not cleared.
	 *
	 * Required at creation because a requisite without a preset is not a record the domain can check
	 * anything about. Which presets a client may name it learns from the two preset methods of this same
	 * group.
	 */
	#[Editable]
	#[Required(['add'])]
	public int $presetId;

	/**
	 * The name of the record itself, not of the person or the organization it holds the details of.
	 *
	 * Not required by the contract although the storage requires it: the refusal of an empty name is the
	 * domain's, and it names the field. Nullable for the same reason - an emptying reaches the domain and
	 * is answered there rather than being turned into a fault of the form.
	 */
	#[Editable]
	public ?string $name;

	#[Editable]
	public ?bool $active;

	#[Editable]
	public ?string $xmlId;

	#[Editable]
	public ?string $originatorId;

	/** Writable, and deliberately not sortable: no index of the storage leads with the column. */
	#[Editable]
	public ?int $sort;

	// Read-only: the moments and the authors of a record are the portal's to fill in.

	public ?DateTime $createdTime;

	public ?DateTime $updatedTime;

	public ?int $createdById;

	public ?int $updatedById;

	// Names and persons.

	#[Editable]
	public ?string $fullName;

	#[Editable]
	public ?string $firstName;

	#[Editable]
	public ?string $lastName;

	#[Editable]
	public ?string $secondName;

	#[Editable]
	public ?string $companyTitle;

	#[Editable]
	public ?string $companyFullName;

	#[Editable]
	public ?string $companyIdentifier;

	#[Editable]
	public ?string $companyRegistrationDate;

	#[Editable]
	public ?string $directorName;

	#[Editable]
	public ?string $accountantName;

	#[Editable]
	public ?string $ceoName;

	#[Editable]
	public ?string $ceoPosition;

	#[Editable]
	public ?string $contactPerson;

	// Means of communication.

	#[Editable]
	public ?string $emailAddress;

	#[Editable]
	public ?string $phoneNumber;

	#[Editable]
	public ?string $faxNumber;

	// The document that proves the identity of an individual.

	#[Editable]
	public ?string $identificationTypeId;

	#[Editable]
	public ?string $identityDocumentType;

	#[Editable]
	public ?string $identityDocumentSeries;

	#[Editable]
	public ?string $identityDocumentNumber;

	#[Editable]
	public ?string $identityDocumentPersonalNumber;

	#[Editable]
	public ?string $identityDocumentIssueDate;

	#[Editable]
	public ?string $identityDocumentIssuedBy;

	#[Editable]
	public ?string $identityDocumentDepartmentCode;

	// Tax and registration numbers.

	#[Editable]
	public ?string $inn;

	#[Editable]
	public ?string $kpp;

	#[Editable]
	public ?string $usrle;

	#[Editable]
	public ?string $ifns;

	#[Editable]
	public ?string $ogrn;

	#[Editable]
	public ?string $ogrnip;

	#[Editable]
	public ?string $okpo;

	#[Editable]
	public ?string $oktmo;

	#[Editable]
	public ?string $okved;

	#[Editable]
	public ?string $edrpou;

	#[Editable]
	public ?string $drfo;

	#[Editable]
	public ?string $kbe;

	#[Editable]
	public ?string $iin;

	#[Editable]
	public ?string $bin;

	#[Editable]
	public ?string $regon;

	#[Editable]
	public ?string $krs;

	#[Editable]
	public ?string $pesel;

	#[Editable]
	public ?string $siret;

	#[Editable]
	public ?string $siren;

	#[Editable]
	public ?string $rcs;

	#[Editable]
	public ?string $cnpj;

	#[Editable]
	public ?string $cpf;

	#[Editable]
	public ?string $stateRegistration;

	#[Editable]
	public ?string $municipalRegistration;

	#[Editable]
	public ?string $legalForm;

	#[Editable]
	public ?string $shareCapital;

	#[Editable]
	public ?string $taxRegimeId;

	#[Editable]
	public ?string $residenceCountry;

	#[Editable]
	public ?string $basisDocument;

	// Certificates and VAT.

	#[Editable]
	public ?string $registrationCertificateSeries;

	#[Editable]
	public ?string $registrationCertificateNumber;

	#[Editable]
	public ?string $registrationCertificateDate;

	#[Editable]
	public ?bool $vatPayer;

	#[Editable]
	public ?string $vatNumber;

	#[Editable]
	public ?string $vatCertificateSeries;

	#[Editable]
	public ?string $vatCertificateNumber;

	#[Editable]
	public ?string $vatCertificateDate;

	/**
	 * The addresses of the requisite, read on request and never written: no method of the group writes an
	 * address, and a client that asks for none is answered none rather than an empty list.
	 */
	#[ElementType(RequisiteAddressDto::class)]
	public ?DtoCollection $addresses;

	/**
	 * The bank details of the requisite, on the same terms as the addresses above.
	 */
	#[ElementType(RequisiteBankDetailDto::class)]
	public ?DtoCollection $bankRequisites;
}

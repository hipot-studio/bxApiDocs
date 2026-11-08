<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DtoGeneratorInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Schema\GeneratedDto;

/**
 * Builds the contract of an address of one owner - a contact or a company.
 *
 * One thing about the group makes a generated contract necessary rather than convenient: the owner of an
 * address is named after the entity of the route. A contact address carries `contactId` and a company
 * address carries `companyId`, so a single declared DTO would have to publish either both names or a
 * neutral one, and both answers are worse than generating the field. Which name it is comes from the
 * configuration of the route ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}
 * passes it as `dtoGeneratorArgs`), while the entity type the schema adds by itself, out of the trusted
 * invariant of the method name.
 *
 * The address fields repeat the format of the address of a lead
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Lead\AddressDto}) by name and by length, and they are
 * declared here rather than read from it: the two are separate published contracts of two groups of
 * methods, and reading one from the other would let a change of the lead group silently change this one.
 *
 * ### What the contract deliberately leaves out
 *
 * The storage of an address keeps more than this: the flag of the primary address of an owner, the
 * anchor the real owner is denormalized into, and the identifier of the address of the location module
 * the row is linked to. None of the three has a meaning to an external client - the anchor is how the
 * owner is found inside, the link is an implementation of the address form - and a published field can
 * never be removed again.
 *
 * ### Identity is read-only, except at the moment an address is created
 *
 * An address never moves onto another owner, another requisite or another type
 * ({@see \Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress} has no setter for any of them), so the owner
 * and the type of address are writable in `add` alone and the requisite is not writable at all: which
 * requisite of the owner holds the address is decided by the scenario, not by the client. A write of any
 * of them in `update` is therefore a validation error naming the field, not a value quietly dropped - the
 * requisite is refused by the DTO itself, while the owner and the type are refused by the request of the
 * change ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\UpdateRequest}), which is where the
 * reason the DTO cannot do it for a field required in `add` is written out.
 */
final class OwnerAddressDtoGenerator implements DtoGeneratorInterface
{
	/** Actions of the group that write an address; the names are the ones of its route configuration. */
	private const WRITE_ACTIONS = ['add', 'update'];

	/** The action an address is created by: the only one the identity of an address is given in. */
	private const CREATE_ACTIONS = ['add'];

	/**
	 * The address fields and the length each of them accepts, in the order
	 * {@see \Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress::toArray()} answers in.
	 *
	 * `countryCode` is checked against `country` as well, but not here: whether a code names the country
	 * next to it is a question about the address as a whole, and the write path of the domain answers it
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Address\OwnerAddressService}).
	 */
	private const MAX_LENGTH_BY_ADDRESS_FIELD = [
		'address1' => 1024,
		'address2' => 1024,
		'city' => 128,
		'postalCode' => 16,
		'region' => 128,
		'province' => 128,
		'country' => 128,
		'countryCode' => 100,
	];

	/**
	 * @param int $entityTypeId The entity type of the route: a contact or a company.
	 * @param string $ownerFieldName The name the owner of an address is published under.
	 * @throws ArgumentException When the route names no owner field, or names an entity type that is not
	 *         a CRM item type at all.
	 */
	public function generate(int $entityTypeId = 0, string $ownerFieldName = ''): GeneratedDto
	{
		if ($ownerFieldName === '')
		{
			throw new ArgumentException('The name of the owner field of an address route is required.');
		}

		$entityType = EntityType::fromId($entityTypeId);

		return new GeneratedDto(
			$this->getGeneratedClassName($entityType),
			$this->getGeneratedNamespace(),
			$this->getFields($ownerFieldName),
		);
	}

	public function getGeneratedNamespace(): string
	{
		return __NAMESPACE__;
	}

	/**
	 * Own prefix rather than the one of another family: a generated class is kept by name - by the
	 * schema cache and by the field cache of the DTO behind it - so two families sharing a name would
	 * hand each other's contract out.
	 */
	public function getGeneratedClassName(EntityType $entityType): string
	{
		return 'OwnerAddress' . $entityType->getCode() . 'Dto';
	}

	/**
	 * @return DtoField[]
	 */
	private function getFields(string $ownerFieldName): array
	{
		$fields = [
			// Sorting by the key of the row is what makes neighbouring pages of a list hold no
			// duplicates; filtering by it is not offered - a single address is read by `get`.
			new DtoField(
				propertyName: 'id',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				sortable: true,
			),
			new DtoField(
				propertyName: 'typeId',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				requiredGroups: self::CREATE_ACTIONS,
				filterable: true,
				sortable: true,
				editableGroups: self::CREATE_ACTIONS,
			),
			new DtoField(
				propertyName: $ownerFieldName,
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				requiredGroups: self::CREATE_ACTIONS,
				filterable: true,
				editableGroups: self::CREATE_ACTIONS,
			),
			new DtoField(
				propertyName: 'requisiteId',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
			),
		];

		foreach (self::MAX_LENGTH_BY_ADDRESS_FIELD as $propertyName => $maxLength)
		{
			$fields[] = new DtoField(
				propertyName: $propertyName,
				propertyType: 'string',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [new Length(max: $maxLength)],
				editableGroups: self::WRITE_ACTIONS,
			);
		}

		return $fields;
	}
}

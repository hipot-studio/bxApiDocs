<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractOwnerRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteAddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteBankDetailDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectedFields;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Translates between the requisite DTO of a route and the public requisite of the domain
 * ({@see Requisite}), in both directions: a record becomes an answer, and a request becomes a write.
 *
 * The entity type of the route is the state of the mapper, as it is of the mapper of the request: it is
 * what a creation writes into the record it builds, and what decides the name the owner is published
 * under ({@see AbstractOwnerRequisiteDto::ownerFieldName()}). A client never sends it and never can.
 *
 * ### The answer is made of what was asked for
 *
 * A field the client did not ask for is not filled here, so it stays out of the response entirely
 * ({@see Dto::toArray()}), and the read did not load it either - both sides are given the one answer of
 * {@see OwnerRequisiteRequestMapper::mapSelect()}. A collection is answered only where it was named: it
 * costs a read of its own, and a client that asked for none is answered none rather than an empty list.
 *
 * ### The write carries what the client sent, and nothing besides
 *
 * A change of a requisite has to tell "the field was not sent" from "the field was sent empty", because
 * emptying a legal detail is a thing a client does on purpose. Both sides of the boundary can say it and
 * they say it differently: the contract says it by whether the field of the DTO was initialized, the
 * model by whether a setter marked the field as changed ({@see Requisite::getChangedFieldValues()}). This
 * is the one place the difference crosses over - a field the client sent has its setter called, with the
 * value as it came and `null` among the values; a field it did not send has nothing called for it.
 *
 * ### Why the accessors are derived from the field name
 *
 * The names of the contract and the names of the fields of a requisite are one vocabulary, the owner
 * apart, and the accessors of the domain are that name with `get` or `set` in front. Writing the
 * seventy of them out here would be a third list to keep in step with the two - and a field added to
 * both and forgotten here would be dropped in silence, which is the failure hardest to notice. So the
 * accessor is derived, and a name that has none is an {@see ArgumentException}: the contracts have come
 * apart, and that is for the portal to report rather than for a client to work around.
 */
final class OwnerRequisiteDtoMapper
{
	public function __construct(
		private readonly EntityType $entityType,
	)
	{
	}

	/**
	 * @param class-string<Dto> $dtoClass the contract of the route, generated per entity type
	 *        ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\OwnerRequisiteDtoGenerator}).
	 * @throws ArgumentException a field of the contract the domain has no accessor for.
	 */
	public function getDtoByRequisite(Requisite $requisite, string $dtoClass, SelectedFields $selectedFields): Dto
	{
		$dto = $dtoClass::create();
		$ownerFieldName = AbstractOwnerRequisiteDto::ownerFieldName($this->entityType);
		$values = $requisite->getFieldValues();

		foreach ($selectedFields->getScalarFields() as $fieldName)
		{
			if (!isset($dto->getFields()[$fieldName]))
			{
				continue;
			}

			$valueName = $fieldName === $ownerFieldName ? Requisite::ownerId : $fieldName;

			// Writes into the field rather than into the property, so a field that was not asked for
			// stays uninitialized and drops out of the response.
			$dto->__set($fieldName, $values[$valueName] ?? null);
		}

		foreach ($selectedFields->getNestedFields() as $fieldName => $nestedFieldNames)
		{
			$collection = $this->relationCollection($requisite, $fieldName, $nestedFieldNames);
			if ($collection !== null)
			{
				$dto->__set($fieldName, $collection);
			}
		}

		return $dto;
	}

	/**
	 * @param Requisite[] $requisites
	 * @param class-string<Dto> $dtoClass
	 * @throws ArgumentException
	 */
	public function getDtoCollectionByRequisites(
		array $requisites,
		string $dtoClass,
		SelectedFields $selectedFields,
	): DtoCollection
	{
		$collection = new DtoCollection($dtoClass);
		foreach ($requisites as $requisite)
		{
			$collection->add($this->getDtoByRequisite($requisite, $dtoClass, $selectedFields));
		}

		return $collection;
	}

	/**
	 * The requisite a creation is to write: the owner it is created for, taken from the route and from
	 * the field the route publishes it under, and every field of content the client sent.
	 *
	 * The owner is set through the model's own address setters, which raise no change mark: the owner of
	 * a requisite is where the record lives rather than something a write carries.
	 *
	 * @throws ArgumentException a field of the contract the domain has no setter for.
	 */
	public function getRequisiteByDto(Dto $dto): Requisite
	{
		$ownerFieldName = AbstractOwnerRequisiteDto::ownerFieldName($this->entityType);
		$values = $dto->toArray(true);
		$requisite = (new Requisite())->setOwnerTypeId($this->entityType->getId());

		if (array_key_exists($ownerFieldName, $values))
		{
			$requisite->setOwnerId($values[$ownerFieldName]);
		}

		return self::applyContentFields($requisite, $values, $ownerFieldName);
	}

	/**
	 * What a change is to write: the fields of content the client sent, each marked as changed and
	 * carrying the value it came with, `null` included - which is how a client empties a cell.
	 *
	 * The owner is not read at all. A change never moves a requisite between owners, and an owner sent
	 * to one is refused by the controller rather than dropped here: the field is declared required in the
	 * creation, and a required field never reaches the check of what may be written
	 * ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}), so the framework will not refuse it on
	 * its own.
	 *
	 * @throws ArgumentException a field of the contract the domain has no setter for.
	 */
	public function getChangesByDto(Dto $dto): Requisite
	{
		return self::applyContentFields(
			new Requisite(),
			$dto->toArray(true),
			AbstractOwnerRequisiteDto::ownerFieldName($this->entityType),
		);
	}

	/**
	 * @param array<string, mixed> $values the fields the client sent, by name; the types are the ones the
	 *        contract declares, as the framework has already refused every other
	 *        ({@see \Bitrix\Rest\V3\Dto\Validation\FieldTypeValidator}).
	 * @throws ArgumentException
	 */
	private static function applyContentFields(
		Requisite $requisite,
		array $values,
		string $ownerFieldName,
	): Requisite
	{
		foreach ($values as $fieldName => $value)
		{
			if ($fieldName === $ownerFieldName)
			{
				continue;
			}

			$setter = 'set' . ucfirst($fieldName);
			if (!method_exists($requisite, $setter))
			{
				throw new ArgumentException(
					sprintf('A requisite takes no `%s` in a write', $fieldName),
					$fieldName,
				);
			}

			$requisite->{$setter}($value);
		}

		return $requisite;
	}

	/**
	 * The collection the client asked for, and `null` when the read did not answer it - which is what a
	 * relation of a requisite says by being `null` rather than by being empty.
	 *
	 * @param string[] $nestedFieldNames
	 * @throws ArgumentException
	 */
	private function relationCollection(
		Requisite $requisite,
		string $fieldName,
		array $nestedFieldNames,
	): ?DtoCollection
	{
		[$records, $dtoClass] = match ($fieldName)
		{
			Requisite::addresses => [$requisite->getAddresses(), RequisiteAddressDto::class],
			Requisite::bankRequisites => [$requisite->getBankRequisites(), RequisiteBankDetailDto::class],
			default => [null, null],
		};

		if ($records === null || $dtoClass === null)
		{
			return null;
		}

		$collection = new DtoCollection($dtoClass);
		foreach ($records as $record)
		{
			$collection->add(self::nestedDto($record, $dtoClass, $nestedFieldNames));
		}

		return $collection;
	}

	/**
	 * One record of a collection, made of the fields asked for inside it.
	 *
	 * A VALUE OF `null` IS NOT ANSWERED, and that is the whole of the rule about the varying composition
	 * of bank details: `null` is how the domain says the field is not part of the jurisdiction of the
	 * requisite ({@see \Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteBankDetail}), and answering it
	 * would tell a client the field exists and is empty. An empty string is answered as it is - the field
	 * is there and was left empty. The five fields that belong to no jurisdiction need no exception here:
	 * they are never `null`, so the same rule always answers them.
	 *
	 * An address has no absent fields at all ({@see \Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteAddress}),
	 * so every field asked for inside one is answered - and asking for the collection by name asks for
	 * all nine of them.
	 *
	 * @param string[] $nestedFieldNames
	 * @param class-string<Dto> $dtoClass
	 * @throws ArgumentException a field of the contract the domain has no accessor for.
	 */
	private static function nestedDto(object $record, string $dtoClass, array $nestedFieldNames): Dto
	{
		$dto = $dtoClass::create();
		foreach ($nestedFieldNames as $fieldName)
		{
			$getter = 'get' . ucfirst($fieldName);
			if (!method_exists($record, $getter))
			{
				throw new ArgumentException(
					sprintf('%s answers no `%s`', get_debug_type($record), $fieldName),
					$fieldName,
				);
			}

			$value = $record->{$getter}();
			if ($value === null)
			{
				continue;
			}

			$dto->__set($fieldName, $value);
		}

		return $dto;
	}
}

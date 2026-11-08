<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.{contact|company}.address.update`: one address, by its identifier, and the fields to write.
 *
 * Only the fields the client sent are written, and which of them may be written at all is the contract of
 * the route: the type of an address and its owner are writable when an address is created and never again,
 * and the requisite the address lives on is not writable at all
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator}).
 *
 * The identifier is read the way {@see GetRequest} reads it, and for the same reason.
 *
 * ### The identity of an address is refused, not ignored
 *
 * A field the contract declares writable in `add` alone is refused here, naming the field. The DTO refuses
 * such a write of its own only when the field declares no required group of any kind: a field that declares
 * one - and the type of an address and its owner are required in `add` - takes the required branch of
 * {@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}, which passes because the field is not required
 * here, and the groups it may be written in are never looked at afterwards. Without the refusal below such a
 * write is answered with the success of a change that did not happen: the mapper of the group writes the
 * address fields alone. The requisite is left to the DTO, {@see self::identityFieldsOf()}.
 *
 * ### A filter is refused, not ignored
 *
 * This request declares no `filter`, and {@see Request::create()} reads nothing it does not declare - a
 * filter would be dropped without a word, and a client that meant to change every address of an owner
 * would be answered with the success of one. Changing a set is a contract of its own, agreed as such, and
 * not a hidden ability of a typical method. The refusal therefore looks at the key the client actually
 * sent, after the parent, which is what decodes the body in the first place.
 *
 * A `filter` that is not an array never gets that far: the transport keeps the key only for an array value
 * when the route declares query parameters, and every route of this group declares one - the entity type of
 * the method name ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}).
 *
 * ### Why the standard request of an update is not extended
 *
 * Every request of the group declares the properties of its standard shape instead of inheriting the class
 * that holds them, and of the five this is the one where the difference matters most.
 * {@see \Bitrix\Rest\V3\Interaction\Request\UpdateRequest} addresses a row by an identifier or by a filter
 * and states the choice in two class attributes. A subclass keeps neither of them: `ReflectionClass` answers
 * with the attributes of one class and {@see \Bitrix\Main\Validation\ValidationService} reads nothing else,
 * so extending it would quietly drop the rules it declares while keeping the `filter` this method has to
 * refuse. Its identifier is a string as well, while an address is identified by an integer everywhere in
 * the group. The two properties this method does have are declared here instead.
 */
final class UpdateRequest extends Request
{
	private const FILTER_PROPERTY = 'filter';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public FieldsStructure $fields;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!is_int($httpRequest->getJsonList()->getRaw('id')))
		{
			throw new InvalidRequestFieldTypeException('id', 'integer');
		}

		if (array_key_exists(self::FILTER_PROPERTY, $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([
				new Error(
					'Filter is not supported here: the method addresses a single address by id.',
					self::FILTER_PROPERTY,
				),
			]);
		}

		self::refuseIdentityFields($request, $dtoClass);

		return $request;
	}

	/**
	 * The identity of an address is given when it is created and is not a value of a change.
	 *
	 * The refusal looks at the keys the client actually sent and never at what they carry: a field that is
	 * not for writing is refused even when it names what is already stored, because accepting it would say
	 * that the field can be written.
	 *
	 * @param class-string<Dto> $dtoClass
	 * @throws RequestValidationException
	 */
	private static function refuseIdentityFields(self $request, string $dtoClass): void
	{
		$sentIdentityFields = array_intersect(
			array_keys($request->fields->getItems()),
			self::identityFieldsOf($dtoClass),
		);
		if ($sentIdentityFields === [])
		{
			return;
		}

		throw new RequestValidationException(array_map(
			static fn(string $field): Error => new Error(
				'The identity of an address is given when it is created and cannot be changed.',
				$field,
			),
			array_values($sentIdentityFields),
		));
	}

	/**
	 * The fields of the contract that carry the identity of an address: writable in `add` alone, and
	 * declaring a required group, which is what keeps the DTO from refusing them here on its own. The
	 * requisite of an address declares no required group and is therefore refused by the DTO itself - a
	 * second refusal of it here would be a copy of a rule that already works.
	 *
	 * @param class-string<Dto> $dtoClass
	 * @return list<string>
	 */
	private static function identityFieldsOf(string $dtoClass): array
	{
		$fields = [];
		/** @var DtoField $field */
		foreach ($dtoClass::create()->getFields() as $field)
		{
			$isCheckedByTheDto = $field->getRequiredGroups() === null;
			$isWritableHere = in_array(
				RequiredGroup::Update->value,
				$field->getEditableGroups() ?? [],
				true,
			);
			if (!$isCheckedByTheDto && !$isWritableHere)
			{
				$fields[] = $field->getPropertyName();
			}
		}

		return $fields;
	}
}

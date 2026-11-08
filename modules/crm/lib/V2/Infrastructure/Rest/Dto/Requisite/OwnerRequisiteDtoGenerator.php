<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DtoGeneratorInterface;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Schema\GeneratedDto;

/**
 * Builds the requisite DTO of one owner entity type out of {@see AbstractOwnerRequisiteDto}.
 *
 * One thing separates the two contracts, and it is the name of the owner: a requisite of a contact names it
 * `contactId` and one of a company `companyId`, because a route that already says which entity it serves
 * has no business asking the client for the type again. Everything else - the composition, what may be
 * written and in which group, what may be filtered and ordered by - is the same for both and is declared
 * once, on the abstract DTO.
 *
 * The entity type is not read from the request: it reaches here as an argument, put there by the schema out
 * of the declaration of the route ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}),
 * which is what makes it trustworthy.
 */
class OwnerRequisiteDtoGenerator implements DtoGeneratorInterface
{
	public function generate(int $entityTypeId = 0): GeneratedDto
	{
		static $dtoByEntityType = [];

		$entityType = EntityType::fromId($entityTypeId);
		$ownerFieldName = AbstractOwnerRequisiteDto::ownerFieldName($entityType);

		if (!isset($dtoByEntityType[$entityType->getId()]))
		{
			$dtoByEntityType[$entityType->getId()] = new GeneratedDto(
				$this->getGeneratedClassName($entityType),
				$this->getGeneratedNamespace(),
				$this->getFields($ownerFieldName),
			);
		}

		return $dtoByEntityType[$entityType->getId()];
	}

	public function getGeneratedNamespace(): string
	{
		return __NAMESPACE__;
	}

	/**
	 * The name of the entity is part of the name of the class, and it has to be: a warm schema cache keeps
	 * generated classes by name, so two entity types sharing one name would be handed each other's
	 * contract - and the one thing that differs between them is the field a client of either has to send.
	 */
	public function getGeneratedClassName(EntityType $entityType): string
	{
		return 'OwnerRequisite' . $entityType->getCode() . 'Dto';
	}

	/**
	 * @return DtoField[]
	 */
	protected function getFields(string $ownerFieldName): array
	{
		$fields = [];
		/** @var DtoField $field */
		foreach ((new OwnerRequisiteDto())->getFields() as $field)
		{
			$isOwner = $field->getPropertyName() === Requisite::ownerId;

			$fields[] = $this->toDynamicField($field, $isOwner ? $ownerFieldName : null);
		}

		return $fields;
	}

	/**
	 * A generated DTO declares no properties of its own, so every field of it is a dynamic one.
	 *
	 * The owner is renamed here and nowhere else: the abstract DTO spells it the way the domain spells it
	 * ({@see Requisite::ownerId}), and the route decides what a client calls it. The title follows the
	 * name, or the machine-readable description of the method would offer a client one name and take
	 * another.
	 *
	 * The validation rules are cloned: the source field belongs to the DTO the fields were read from, and
	 * the generated contract must not share mutable rules with it. Only rules of the module travel this
	 * way at all - the generated class is written out with `var_export` and evaluated back
	 * ({@see \Bitrix\Rest\V3\Dto\Generator}), and a rule of the kernel declares no `__set_state` to be
	 * read back through. Such a rule is left behind rather than carried: carried, it would not fail the
	 * field it stands on but the evaluation of the whole class, and with it the lookup of a method for
	 * every module of the portal.
	 */
	private function toDynamicField(DtoField $field, ?string $renameTo): DtoField
	{
		$fieldData = $field->toArray();
		$fieldData['type'] = DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD;
		$fieldData['validationRules'] = array_values(array_map(
			static fn(object $rule): object => clone $rule,
			array_filter(
				$fieldData['validationRules'] ?? [],
				static fn(object $rule): bool => method_exists($rule, '__set_state'),
			),
		));

		if ($renameTo !== null)
		{
			$fieldData['propertyName'] = $renameTo;
			$fieldData['title'] = $renameTo;
		}

		return DtoField::fromArray($fieldData);
	}
}

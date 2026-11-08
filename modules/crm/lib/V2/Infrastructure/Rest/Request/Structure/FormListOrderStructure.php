<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Ordering\OrderItem;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The order of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListFormRequest}: the order of the
 * lists of CRM, plus the one thing a page of forms is ordered by that the common structure refuses to
 * read.
 *
 * {@see ItemListOrderStructure} resolves the name of a key as a field of the top level and turns down
 * any name carrying a dot by an explicit check, so a page of forms could not be ordered by the target
 * entity - which the answer carries inside a section. This structure adds exactly that:
 * {@see self::SORTABLE_PATHS} is the closed list of paths of sections a key may name, and a path
 * outside it is refused as an unknown property of the contract, the same refusal the common structure
 * answers today.
 *
 * Everything else is kept as the common structure has it, refusal by refusal: a list instead of a map
 * of keys, a name the contract does not carry, a field published without {@see Sortable}, a direction
 * outside `ASC` and `DESC`. The directions are read in a pass of their own before the names are, the
 * way the common structure reads them, so a request carrying more than one mistake is answered the
 * same refusal too. The order of the keys is kept as the request wrote them, because a page ordered
 * by several keys is ordered by them in that order.
 *
 * The common structure is not extended in place: it is shared by other groups of methods of the
 * module, and widening what it accepts would widen the contract of every one of them too.
 */
final class FormListOrderStructure extends Structure
{
	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\CrmSettingsDto::$entityScheme */
	private const SORTABLE_PATHS = [
		'crm.entityScheme',
	];

	/** @param OrderItem[] $items */
	private function __construct(private readonly array $items)
	{
	}

	/**
	 * @throws InvalidOrderException
	 * @throws UnknownDtoPropertyException a name the contract does not carry, or a path outside the
	 *         closed list of this structure
	 * @throws DtoFieldRequiredAttributeException a field the contract publishes without {@see Sortable}
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new InvalidOrderException($value);
		}

		foreach ($value as $direction)
		{
			if (!is_string($direction))
			{
				throw new InvalidOrderException($direction);
			}
		}

		$items = [];
		foreach ($value as $fieldName => $direction)
		{
			$fieldName = (string)$fieldName;
			self::requireSortableField($dtoClass, $fieldName);

			$order = Order::tryFrom(strtoupper($direction));
			if ($order === null)
			{
				throw new InvalidOrderException($direction);
			}

			$items[] = new OrderItem($fieldName, $order);
		}

		return new self($items);
	}

	/** @return array<string, string> */
	public function getList(): array
	{
		$result = [];
		foreach ($this->items as $item)
		{
			$result[$item->getProperty()] = $item->getOrder()->value;
		}

		return $result;
	}

	/**
	 * @throws UnknownDtoPropertyException
	 * @throws DtoFieldRequiredAttributeException
	 */
	private static function requireSortableField(string $dtoClass, string $fieldName): void
	{
		if (!str_contains($fieldName, '.'))
		{
			self::requireSortableAttribute(self::requireDto($dtoClass), $fieldName);

			return;
		}

		if (!in_array($fieldName, self::SORTABLE_PATHS, true))
		{
			throw new UnknownDtoPropertyException(self::requireDto($dtoClass)->getShortName(), $fieldName);
		}

		[$sectionName, $sectionFieldName] = explode('.', $fieldName, 2);
		self::requireSortableAttribute(self::requireSectionDto($dtoClass, $sectionName), $sectionFieldName);
	}

	/**
	 * @throws UnknownDtoPropertyException
	 * @throws DtoFieldRequiredAttributeException
	 */
	private static function requireSortableAttribute(Dto $dto, string $fieldName): void
	{
		$field = $dto->getFields()[$fieldName] ?? null;
		if (!$field instanceof DtoField)
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
		}

		if (!$field->isSortable())
		{
			throw new DtoFieldRequiredAttributeException($dto->getShortName(), $fieldName, Sortable::class);
		}
	}

	/**
	 * The contract of the section the path leads into. A path of {@see self::SORTABLE_PATHS} that leads
	 * elsewhere is a mistake in that list rather than anything a client could send.
	 */
	private static function requireSectionDto(string $dtoClass, string $sectionName): Dto
	{
		$sectionField = self::requireDto($dtoClass)->getFields()[$sectionName] ?? null;
		$sectionDtoClass = $sectionField instanceof DtoField
			? ($sectionField->getElementType() ?? $sectionField->getPropertyType())
			: null;
		if (!is_subclass_of($sectionDtoClass, Dto::class))
		{
			throw new \LogicException('The order of the form list names a path that is not a section: ' . $sectionName);
		}

		return self::requireDto($sectionDtoClass);
	}

	private static function requireDto(string $dtoClass): Dto
	{
		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

		return $dto;
	}
}

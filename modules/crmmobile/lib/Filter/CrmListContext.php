<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Filter;

use Bitrix\Crm\Filter;
use Bitrix\Crm\Kanban;
use Bitrix\Crm\Service;
use Bitrix\Crm\Service\Container;
use Bitrix\CrmMobile\Kanban\GridId;
use Bitrix\Main;
use Bitrix\Mobile\Filter\ListContext;

/**
 * One CRM list for the unified filter: an entity type plus a funnel.
 *
 * The list is described by the same object the mobile CRM list itself is built from -
 * Crm\Kanban\Entity with the mobile grid id installed into it. That is what makes the filter and
 * the list address one storage, the one shared with the web CRM list, without repeating how CRM
 * derives a grid id or which presets a funnel ships with.
 */
final class CrmListContext implements ListContext
{
	private function __construct(
		private readonly int $entityTypeId,
		private readonly int $categoryId,
		private readonly Kanban\Entity $entity,
		private readonly Service\Factory $factory,
	)
	{
	}

	/**
	 * Null when the entity type is unknown to CRM: the caller words the error.
	 */
	public static function make(string $entityTypeName, int $categoryId): ?self
	{
		$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeName);
		if ($entityTypeId === \CCrmOwnerType::Undefined)
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return null;
		}

		$entity = Kanban\Entity::getInstance(\CCrmOwnerType::ResolveName($entityTypeId));
		if ($entity === null)
		{
			return null;
		}

		$categoryId = $factory->isCategoriesSupported() ? $categoryId : 0;

		// the same pair of calls the mobile CRM list makes (Kanban\ControllerStrategy\KanbanStrategy):
		// the funnel is part of the address of the list, and the mobile grid id is a subclass of the
		// web one, so it stays the hook it was meant to be
		$entity->setGridIdInstance(new GridId($entityTypeId), $entityTypeId)->setCategoryId($categoryId);

		return new self($entityTypeId, $categoryId, $entity, $factory);
	}

	public function canRead(): bool
	{
		$permissions = Container::getInstance()->getUserPermissions()->entityType();

		return $this->entity->isCategoriesSupported()
			? $permissions->canReadItemsInCategory($this->entityTypeId, $this->categoryId)
			: $permissions->canReadItems($this->entityTypeId);
	}

	public function getOptions(): Main\UI\Filter\Options
	{
		return $this->entity->getFilterOptions();
	}

	public function getFieldArrays(): array
	{
		// Entity::getFilter() is protected, so the filter is built the way CRM builds it - over the
		// grid id the entity resolved. The settings come from getSettingsByGridId() and not from
		// createEntitySettings(): the flags of the list live there, and it is one of them that adds
		// the fields of the client to a deal - the two extra comment rows of the web form are
		// the comment of the contact and of the company.
		$filter = Filter\Factory::createEntityFilter(
			Filter\Factory::getSettingsByGridId($this->entityTypeId, $this->entity->getGridId()),
		);

		return self::withoutRestrictedFields($filter->getFieldArrays(), $this->entity->getFieldsRestrictions());
	}

	/**
	 * Fields a restriction of the tariff closed are left out of the dictionary instead of being drawn
	 * as rows that narrow nothing.
	 *
	 * The web list draws such a row and sells the tariff from it - that is what
	 * `FieldRestrictionManager::fetchRestrictedFieldsEngine()` builds the lock and the slider for. The
	 * drawer of the mobile filter has no such offer to make, and the same manager already takes the
	 * values of these fields out of the storage (`removeRestrictedFieldsFromFilter()`, applied by
	 * `Kanban\Entity::getFilterOptions()`), so a row of one would come up empty and stay empty.
	 *
	 * @param array<int, array> $fieldArrays raw web descriptors
	 * @param string[] $restrictedFieldIds as `Kanban\Entity::getFieldsRestrictions()` names them
	 * @return array<int, array>
	 */
	public static function withoutRestrictedFields(array $fieldArrays, array $restrictedFieldIds): array
	{
		if (empty($restrictedFieldIds))
		{
			return $fieldArrays;
		}

		return array_values(array_filter($fieldArrays, static function (array $field) use ($restrictedFieldIds): bool {
			$id = (string)($field['id'] ?? '');

			// a descriptor with no id names no field: there is nothing to weigh a restriction against
			return $id === '' || !in_array($id, $restrictedFieldIds, true);
		}));
	}

	/**
	 * The groups of the web filter of this list: the entity itself, then the client entities, then
	 * the activity search - the very sections the web names its field sectionId after.
	 *
	 * filterGridSupportedSections() is deliberately not applied: it drops the section the grid has
	 * no columns for, and the activity one is exactly that - a section of the filter only.
	 */
	public function getSections(): array
	{
		$sections = Filter\HeaderSections::getInstance()->sections($this->factory);

		return array_values(array_map(
			static fn (array $section): array => [
				'id' => (string)$section['id'],
				'name' => (string)$section['name'],
			],
			$sections,
		));
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\Service\WebForm\WebFormScenarioService;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\EntityField;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\Scenario;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\ScenarioCategory;
use Bitrix\Crm\V2\Internal\Exception\WebForm\UnknownFormEntityException;
use Bitrix\Crm\WebForm\EntityFieldProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;

/**
 * The three dictionaries an external form editor is built on. Every one of them is read from the
 * portal service that already answers the internal editor, so both editors see one catalogue.
 *
 * @internal
 */
final class FormDictionaryRepository
{
	/**
	 * The field groups left out of the dictionary. The set is fixed here instead of travelling in
	 * from the caller and repeats what the internal editor asks for by default, so an external
	 * editor is offered the same fields.
	 *
	 * Requisites are the only group of the four the portal knows how to leave out: the rest name
	 * entities hidden from a form anyway.
	 */
	private const HIDDEN_FIELD_TYPES = [\CCrmOwnerType::Requisite];

	public function __construct(private readonly ?WebFormScenarioService $scenarioService = null)
	{
	}

	/**
	 * @return list<Scenario>
	 * @throws ObjectNotFoundException
	 */
	public function getScenarios(): array
	{
		$publishedCategoryIds = array_flip(array_map(
			static fn (ScenarioCategory $category): string => $category->id,
			$this->getScenarioCategories(),
		));

		$scenarios = [];
		foreach ($this->scenarioService()->getScenarioList() as $configuration)
		{
			$scenario = Scenario::fromConfiguration($configuration);
			if (isset($publishedCategoryIds[$scenario->categoryId]))
			{
				$scenarios[] = $scenario;
			}
		}

		return $scenarios;
	}

	/**
	 * The catalogue drops the group of the bookings while the portal has no module keeping them,
	 * so an editor is never offered a scenario it cannot carry out.
	 *
	 * @return list<ScenarioCategory>
	 * @throws ObjectNotFoundException
	 */
	public function getScenarioCategories(): array
	{
		$categories = [];
		foreach ($this->scenarioService()->getScenarioCategoryList() as $configuration)
		{
			$categories[] = ScenarioCategory::fromConfiguration($configuration);
		}

		return $categories;
	}

	/**
	 * Returns every CRM field a form may be built of, the synthetic groups of the catalogue and of
	 * the activity included: they carry the product and the activity fields, which belong to no
	 * entity of their own and would be lost with the groups.
	 *
	 * Naming the entities narrows the read to them, and the names travel down to the provider, which
	 * drops the rest of the map before it builds a field of them. That is where the cost of the
	 * dictionary is: on a portal of hundreds of smart processes the whole of it takes seconds, and
	 * four entities take milliseconds.
	 *
	 * The performance gate measured that cost: about 2056 fields, ~4.5 s and ~96 MB for a call
	 * without a filter against ~1.15 s and 64 MB for a call naming an entity, and nothing is cached
	 * between calls. The method publishes no page at all - a decision of the ADR of the family - so
	 * naming the entities lowers the cost of a call but does not stand in for a page.
	 *
	 * The provider keeps the three synthetic groups whatever it is asked for, so they are dropped
	 * here instead: a caller asking for the fields of one entity is answered the fields of that one.
	 *
	 * @param null|list<string> $entityNames the entities to answer about, or null for all of them
	 * @return list<EntityField>
	 * @throws UnknownFormEntityException one of the names belongs to no entity of the dictionary
	 * @throws SystemException
	 */
	public function getEntityFields(?array $entityNames = null): array
	{
		$requested = $entityNames === null ? null : self::normalizeEntityNames($entityNames);
		if ($requested === [])
		{
			return [];
		}

		if ($requested !== null)
		{
			self::assertEntitiesAreKnown($requested);
		}

		$fields = [];
		foreach (EntityFieldProvider::getFields(self::HIDDEN_FIELD_TYPES, null, $requested ?? []) as $plainField)
		{
			$field = EntityField::fromPlainField($plainField);
			if ($requested === null || in_array($field->entityName, $requested, true))
			{
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * The dictionary answers under the names of the tree the provider builds, which is the map of
	 * the portal narrowed to the request, without the entities hidden from a form, plus the
	 * synthetic groups. Asking the provider for that tree is what the read does next anyway, and the
	 * provider keeps it, so the check adds no work of its own.
	 *
	 * @param list<string> $requested
	 * @throws UnknownFormEntityException
	 * @throws SystemException
	 */
	private static function assertEntitiesAreKnown(array $requested): void
	{
		$tree = EntityFieldProvider::getFieldsTree(self::HIDDEN_FIELD_TYPES, null, $requested);

		$unknown = array_values(array_diff($requested, array_keys($tree)));
		if ($unknown !== [])
		{
			throw new UnknownFormEntityException($unknown);
		}
	}

	/**
	 * The provider upper-cases the names it is given, and the dictionary answers under those, so a
	 * name reaches both the filter and the comparison in the one shape.
	 *
	 * @param list<string> $entityNames
	 * @return list<string>
	 */
	private static function normalizeEntityNames(array $entityNames): array
	{
		return array_values(array_unique(array_map('strtoupper', $entityNames)));
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function scenarioService(): WebFormScenarioService
	{
		if ($this->scenarioService !== null)
		{
			return $this->scenarioService;
		}

		/** @var WebFormScenarioService $service */
		$service = ServiceLocator::getInstance()->get('crm.service.webform.scenario');

		return $service;
	}
}

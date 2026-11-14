<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SmartProcessStartForms;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Json;

/**
 * Whether creating an item of a smart process opens its CRM form instead of the card, and what an entry
 * point (list, kanban, related items) has to carry for the browser to open that form.
 */
final class CreateByForm
{
	public const ENTITY_TYPE_ATTRIBUTE = 'data-crm-start-catalog-entity-type-id';
	public const FORM_ATTRIBUTE = 'data-crm-start-catalog-form-id';
	public const CONTEXT_ATTRIBUTE = 'data-crm-start-catalog-context';
	public const JS_EXTENSION = 'crm.start-catalog';

	/**
	 * An entry point keeps the address of the card on itself, and the side panel of the product opens
	 * any such address in a slider of its own - it answers the click first, before the extension gets
	 * it. This attribute is how the product is told to keep away: the click belongs to the form.
	 */
	private const SLIDER_IGNORE_ATTRIBUTE = 'data-slider-ignore-autobinding';

	private const ATTRIBUTE_PREFIX = 'data-';

	/** The entry context is what the card itself would have set on the item, and nothing besides. */
	private const NUMERIC_CONTEXT_KEYS = ['parentTypeId', 'parentId', 'categoryId'];
	private const TEXT_CONTEXT_KEYS = ['stageId'];

	/**
	 * The states of the bound forms of every type that creates its items by the form, read once a hit:
	 * the list, the kanban and the tab of related items ask about creation for each type they show.
	 *
	 * @var array<int, array{state: BindingState, form: array|null, boundFormId: int}>|null
	 */
	private static ?array $bindings = null;

	public static function isEnabled(int $entityTypeId): bool
	{
		return self::getOpenParameters($entityTypeId) !== null;
	}

	/**
	 * The same decision as data: the kanban hands it to its javascript, which has no markup of ours
	 * to read attributes from.
	 *
	 * @return array{entityTypeId: int, formId: int}|null
	 */
	public static function getOpenParameters(int $entityTypeId): ?array
	{
		if (!Feature::enabled(SmartProcessStartForms::class))
		{
			return null;
		}

		$type = self::getType($entityTypeId);
		if ($type?->getIsCreateByFormEnabled() !== true)
		{
			return null;
		}

		// the chosen form can be deleted, switched off, repointed or made to merge duplicates after it was chosen
		$binding = self::getBinding($type);
		if ($binding === null || $binding['state'] !== BindingState::Live)
		{
			return null;
		}

		// a plan that forbids new items or a missing right in the funnel of the form is shown by the entry
		// point itself, the way the card path shows it
		if (!self::isFormPermitted($entityTypeId, $binding['form']))
		{
			return null;
		}

		return [
			'entityTypeId' => $entityTypeId,
			'formId' => (int)$binding['form']['id'],
		];
	}

	public static function isAddPermittedByTariff(int $entityTypeId): bool
	{
		return RestrictionManager::getAddOperationRestriction($entityTypeId)->hasPermission();
	}

	/**
	 * The funnel the form puts its item into. A funnel of another smart process or a deleted one takes
	 * no item at all, so such a form has no funnel.
	 *
	 * @param array{categoryId?: int} $form a record of {@see FormResolver}
	 * @param Category[]|null $categories the funnels of the smart process read beforehand, null to ask the factory
	 */
	public static function getFormCategoryId(int $entityTypeId, array $form, ?array $categories = null): ?int
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return null;
		}

		// only the funnels of its own smart process are looked among
		$categories ??= $factory->getCategories();

		$categoryId = (int)($form['categoryId'] ?? 0);
		foreach ($categories as $category)
		{
			if ($categoryId <= 0 ? $category->getIsDefault() : (int)$category->getId() === $categoryId)
			{
				return (int)$category->getId();
			}
		}

		return null;
	}

	/**
	 * Whether the employee may create an item through the form: the plan allows new items and the employee
	 * may add them in the funnel of the form. The funnel the employee starts from is not part of it.
	 *
	 * @param array{categoryId?: int} $form a record of {@see FormResolver}
	 * @param Category[]|null $categories the funnels of the smart process read beforehand, null to ask the factory
	 */
	public static function isFormPermitted(
		int $entityTypeId,
		array $form,
		?UserPermissions $permissions = null,
		?array $categories = null,
	): bool
	{
		$categoryId = self::getFormCategoryId($entityTypeId, $form, $categories);
		if ($categoryId === null)
		{
			return false;
		}

		if (!self::isAddPermittedByTariff($entityTypeId))
		{
			return false;
		}

		$permissions ??= Container::getInstance()->getUserPermissions();

		return $permissions->entityType()->canAddItemsInCategory($entityTypeId, $categoryId);
	}

	/**
	 * Forgets the forms read in this hit, for the code that changes them and asks again in the same one.
	 */
	public static function clearCache(): void
	{
		self::$bindings = null;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, string>
	 */
	public static function getOpenAttributes(int $entityTypeId, array $context = []): array
	{
		$parameters = self::getOpenParameters($entityTypeId);
		if ($parameters === null)
		{
			return [];
		}

		$attributes = [
			self::ENTITY_TYPE_ATTRIBUTE => (string)$parameters['entityTypeId'],
			self::FORM_ATTRIBUTE => (string)$parameters['formId'],
			self::SLIDER_IGNORE_ATTRIBUTE => 'true',
		];

		$entryContext = self::prepareContext($context);
		if ($entryContext !== [])
		{
			$attributes[self::CONTEXT_ATTRIBUTE] = Json::encode($entryContext);
		}

		return $attributes;
	}

	/**
	 * The same attributes for a button of the toolbar: it is given their names without the prefix and
	 * puts the prefix back itself.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, string>
	 */
	public static function getOpenDataset(int $entityTypeId, array $context = []): array
	{
		$dataset = [];
		foreach (self::getOpenAttributes($entityTypeId, $context) as $name => $value)
		{
			$dataset[substr($name, strlen(self::ATTRIBUTE_PREFIX))] = $value;
		}

		return $dataset;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, int|string>
	 */
	private static function prepareContext(array $context): array
	{
		$prepared = [];

		foreach (self::NUMERIC_CONTEXT_KEYS as $key)
		{
			$value = $context[$key] ?? null;
			if (is_scalar($value) && (int)$value > 0)
			{
				$prepared[$key] = (int)$value;
			}
		}

		foreach (self::TEXT_CONTEXT_KEYS as $key)
		{
			$value = $context[$key] ?? null;
			if (is_scalar($value) && trim((string)$value) !== '')
			{
				$prepared[$key] = trim((string)$value);
			}
		}

		return $prepared;
	}

	/**
	 * A type whose form was chosen anew in this hit is asked about again: the remembered answer is about
	 * the form chosen before.
	 *
	 * @return array{state: BindingState, form: array|null}|null
	 */
	private static function getBinding(Type $type): ?array
	{
		$entityTypeId = $type->getEntityTypeId();
		$boundFormId = (int)$type->getStartCatalogFormId();

		if (
			self::$bindings === null
			|| !array_key_exists($entityTypeId, self::$bindings)
			|| self::$bindings[$entityTypeId]['boundFormId'] !== $boundFormId
		)
		{
			$boundFormIds = [$entityTypeId => $boundFormId];
			foreach (self::getTypesCollection() as $enabledType)
			{
				if ($enabledType->getIsCreateByFormEnabled() === true)
				{
					$boundFormIds[$enabledType->getEntityTypeId()] = (int)$enabledType->getStartCatalogFormId();
				}
			}

			$bindings = (new FormResolver())->getBindings(array_keys($boundFormIds));
			foreach ($bindings as $id => $binding)
			{
				self::$bindings[$id] = $binding + ['boundFormId' => $boundFormIds[$id] ?? 0];
			}
		}

		return self::$bindings[$entityTypeId] ?? null;
	}

	private static function getType(int $entityTypeId): ?Type
	{
		foreach (self::getTypesCollection() as $type)
		{
			if ($type->getEntityTypeId() === $entityTypeId)
			{
				return $type;
			}
		}

		return null;
	}

	/**
	 * A snapshot: the iterator of the collection is shared, and the resolver walks it too. The type is
	 * not taken from the container by id - that one is remembered past the invalidation of the collection
	 * and would answer with the settings the type had before they were changed.
	 *
	 * @return Type[]
	 */
	private static function getTypesCollection(): array
	{
		return Container::getInstance()->getDynamicTypesMap()->getTypesCollection()->getAll();
	}
}

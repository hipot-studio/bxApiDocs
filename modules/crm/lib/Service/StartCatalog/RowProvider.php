<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Bizproc\Public\Event\UserProcesses\OnCollectStartableProcessesEvent\StartableProcess;
use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Category\Entity\ItemCategory;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SmartProcessStartForms;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Localization\Loc;

/**
 * The rows the module offers to the catalog of processes a user can start.
 */
class RowProvider
{
	private const ROW_ID_PREFIX = 'crm-type-';
	private const JS_EXTENSION = 'crm.start-catalog';

	private readonly FormResolver $formResolver;

	public function __construct(?FormResolver $formResolver = null)
	{
		$this->formResolver = $formResolver ?? new FormResolver();
	}

	/**
	 * The catalog collects rows of other modules without asking anyone about them, so whether the
	 * user may start the process is decided here and nowhere else.
	 *
	 * @return StartableProcess[]
	 */
	public function getProcesses(int $userId, string $searchString = ''): array
	{
		if (!Feature::enabled(SmartProcessStartForms::class))
		{
			return [];
		}

		$permissions = Container::getInstance()->getUserPermissions($userId);

		$candidates = [];
		// a snapshot: the iterator of the collection is shared, and the resolver walks it too
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection()->getAll() as $type)
		{
			if (
				$type->getIsStartCatalogEnabled() === true
				&& (int)$type->getStartCatalogFormId() > 0
				&& $this->matchesSearchString((string)$type->getTitle(), $searchString)
			)
			{
				$candidates[$type->getEntityTypeId()] = $type;
			}
		}

		// the funnels of the whole catalog are read at once: a factory reads those of its own type alone
		$categories = $this->getCategories(array_keys($candidates));

		$types = [];
		foreach ($candidates as $entityTypeId => $type)
		{
			if ($this->canAddItems($permissions, $entityTypeId, $categories[$entityTypeId] ?? []))
			{
				$types[$entityTypeId] = $type;
			}
		}

		// the forms of the whole catalog are asked for at once: a request per row would grow with it
		$bindings = $this->formResolver->getBindings(array_keys($types));

		$rows = [];
		foreach ($types as $entityTypeId => $type)
		{
			$binding = $bindings[$entityTypeId] ?? null;
			if ($binding === null || $binding['state'] === BindingState::None)
			{
				continue;
			}

			// only a living form is opened, and only while the plan lets the items be created and the user may
			// add them in the funnel of the form; otherwise the row leads to the section alone, which tells
			// about the plan itself
			$form = $binding['state'] === BindingState::Live ? $binding['form'] : null;
			$isFormOpened = $form !== null && CreateByForm::isFormPermitted(
				$entityTypeId,
				$form,
				$permissions,
				$categories[$entityTypeId] ?? [],
			);
			$openedForm = $isFormOpened ? $form : null;

			$rows[] = [
				'process' => $this->createProcess($type, $entityTypeId, $openedForm),
				'formId' => $form === null ? null : (int)$form['id'],
			];
		}

		return $this->withLastStartedAt($rows);
	}

	/**
	 * The answer of {@see UserPermissions\EntityPermissions\Type::canAddItems()} - may the user add items in
	 * at least one funnel - given from the funnels read beforehand instead of those the factory reads.
	 *
	 * @param Category[] $categories
	 */
	private function canAddItems(UserPermissions $permissions, int $entityTypeId, array $categories): bool
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return false;
		}

		if (!$factory->isCategoriesSupported())
		{
			return $permissions->entityType()->canAddItemsInCategory($entityTypeId, 0);
		}

		foreach ($categories as $category)
		{
			if ($permissions->entityType()->canAddItemsInCategory($entityTypeId, (int)$category->getId()))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the way {@see ItemCategoryTable::getItemCategoriesByEntityTypeId()} reads them for a factory.
	 *
	 * @param int[] $entityTypeIds
	 * @return array<int, Category[]>
	 */
	private function getCategories(array $entityTypeIds): array
	{
		if (empty($entityTypeIds))
		{
			return [];
		}

		$list = ItemCategoryTable::query()
			->whereIn('ENTITY_TYPE_ID', $entityTypeIds)
			->setSelect(['*'])
			->addOrder('SORT')
			->setCacheTtl(3600)
			->exec()
		;

		$categories = [];
		while ($item = $list->fetchObject())
		{
			$categories[(int)$item->getEntityTypeId()][] = new ItemCategory($item);
		}

		return $categories;
	}

	/**
	 * @param array{id: int, code: string, securityCode: string}|null $form
	 */
	private function createProcess(Type $type, int $entityTypeId, ?array $form): StartableProcess
	{
		$title = (string)$type->getTitle();

		$process = new StartableProcess(
			self::ROW_ID_PREFIX . $entityTypeId,
			$title,
			$this->getSectionUrl($entityTypeId),
		);
		$process->setDescription(
			(string)Loc::getMessage('CRM_START_CATALOG_ROW_DESCRIPTION', ['#TITLE#' => $title])
		);

		// the row is pinned by the document type of the smart process, which it has whether or not
		// business processes are used on it
		$documentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
		if (is_array($documentType))
		{
			$process->setDocumentType($documentType);
		}

		if ($form !== null)
		{
			$process
				->setJsExtension(self::JS_EXTENSION)
				->setButtonAttributes([
					'data-crm-start-catalog-entity-type-id' => (string)$entityTypeId,
					'data-crm-start-catalog-form-id' => (string)$form['id'],
				])
			;
		}

		return $process;
	}

	/**
	 * Submitting the bound form is what starting the process means here, and the dates of the whole
	 * catalog are asked for at once: a request per row would grow with the catalog.
	 *
	 * @param array<int, array{process: StartableProcess, formId: int|null}> $rows
	 * @return StartableProcess[]
	 */
	private function withLastStartedAt(array $rows): array
	{
		$dates = $this->formResolver->getLastSubmissionDates(
			array_filter(array_column($rows, 'formId'))
		);

		$processes = [];
		foreach ($rows as $row)
		{
			$formId = $row['formId'];
			$processes[] = $row['process']->setLastStartedAt($formId === null ? null : ($dates[$formId] ?? null));
		}

		return $processes;
	}

	/**
	 * The form is opened over the catalog and nowhere else, so the row leads to the smart process
	 * itself: the public address of a CRM form is a page of the landing module, which the portal may
	 * not have at all - and asking for the address makes the product create that page.
	 */
	private function getSectionUrl(int $entityTypeId): string
	{
		return (string)Container::getInstance()->getRouter()->getItemListUrlInCurrentView($entityTypeId);
	}

	private function matchesSearchString(string $title, string $searchString): bool
	{
		return $searchString === '' || mb_stripos($title, $searchString) !== false;
	}
}

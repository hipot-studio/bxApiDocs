<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Transport of the unified filter (CONTRACT-DTO-01 and CONTRACT-API-02) for a module that owns
 * filterable lists. Meant for a `Bitrix\Main\Engine\Controller` descendant.
 *
 * All seven actions are the same everywhere - read the dictionary, read the presets, write one,
 * delete one, pin one, publish one, restore the set - because they all end in the filter storage
 * shared with the web. What differs is only which list is meant, so a module implements
 * createListContext() and getNormalizer() and nothing else.
 *
 * It is a trait rather than a base controller on purpose: the single inheritance slot of a module
 * belongs to that module. Its controllers already share a base with its own pre-filters, and the
 * filter actions have to be able to carry the module's own ones (CRM hangs a restriction check on
 * every read and write of its lists).
 *
 * The list is named by the `context` map, opaque to this trait: the module reads its own keys out
 * of it. This is what lets one client component talk to Tasks and CRM without knowing either.
 *
 * The actions of `main.ui.filter` are deliberately not reused: its component controller takes the
 * grid id straight from the client with no permission check and builds Options without the default
 * presets of the list, which loses them on read and erases them on write.
 */
trait FilterActionsTrait
{
	/**
	 * Null when the module cannot name a list from this context.
	 *
	 * @param array $context as the client sent it, unvalidated
	 */
	abstract protected function createListContext(array $context): ?ListContext;

	/**
	 * The rule that turns raw web descriptors into CONTRACT-DTO-01: the selectors a module offers
	 * are its own business, and they are the same for every list of that module.
	 */
	abstract protected function getNormalizer(): FieldDescriptorNormalizer;

	/**
	 * Configuration of the seven actions. A module merges it into its own configureActions():
	 * `return $this->configureFilterActions() + [...]` keeps the entries below, so writing
	 * actions cannot lose their POST-only rule by accident.
	 */
	protected function configureFilterActions(): array
	{
		$readOnly = ['+prefilters' => [new CloseSession()]];
		$postOnly = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'getFilterFields' => $readOnly,
			'getFilterFieldItems' => $readOnly,
			'getSavedFilters' => $readOnly,
			'saveFieldSet' => $postOnly,
			'saveFilter' => $postOnly,
			'reorderFilters' => $postOnly,
			'deleteFilter' => $postOnly,
			'pinFilter' => $postOnly,
			'setForAll' => $postOnly,
			'unsetForAll' => $postOnly,
			'restoreFilters' => $postOnly,
		];
	}

	/**
	 * @param string $dictionaryLangId language of the vocabulary the client already holds; the words are
	 *                                 then left out of the answer, see FilterDictionary
	 */
	public function getFilterFieldsAction(array $context = [], string $dictionaryLangId = ''): ?array
	{
		$list = $this->resolveListContext($context);
		if ($list === null)
		{
			return null;
		}

		$options = $list->getOptions();
		$storage = new SavedFilterStorage($options);
		$currentFilter = $storage->getCurrentFilter();

		// the reference book of a field the filters of this list never touch stays on the server until
		// the picker of that field is opened (DeferredItems), and what is left of the shared ones then
		// travels once with a pointer in the fields (ItemSetIndex)
		$folded = ItemSetIndex::fold(DeferredItems::withhold(
			$this->getNormalizer()->normalizeList($list->getFieldArrays()),
			$this->getUsedFieldIds($storage, $currentFilter),
		));

		return [
			'gridId' => $options->getId(),
			'defaultPresetId' => (string)$options->getDefaultFilterId(),
			'currentPresetId' => (string)$options->getCurrentFilterId(),
			// the pointer alone leaves the form empty while the web shows conditions: the values and
			// the visible rows of the current filter come with the dictionary
			'currentFilter' => $currentFilter,
			'fields' => $folded['fields'],
			'itemSets' => $folded['itemSets'],
			// empty for a list the web does not group: the form then draws one flat list of fields
			'sections' => $list->getSections(),
			// the words of the controls: they belong to no list, come from the web filter itself and are
			// kept by the client, so they are sent when the client has none of the current language and
			// left out otherwise - a short list would otherwise pay more for the words than it saves
			'dictionary' => FilterDictionary::isKeptVocabularyFresh($dictionaryLangId)
				? null
				: (new FilterDictionary())->toArray(),
		];
	}

	/**
	 * Values of one field of the list - the reference book the dictionary left on the server. Asked
	 * for when the picker of the field is opened for the first time (DeferredItems).
	 *
	 * An empty list is a legitimate answer, so the client can tell "the portal has none of these" from
	 * "the request failed" and does not ask again for a field that simply has nothing to choose from.
	 */
	public function getFilterFieldItemsAction(string $fieldId, array $context = []): ?array
	{
		$list = $this->resolveListContext($context);
		if ($list === null)
		{
			return null;
		}

		$fields = $this->getNormalizer()->normalizeList($list->getFieldArrays());
		$items = DeferredItems::itemsOf($fields, $fieldId);
		if ($items === null)
		{
			$this->addError(new Error('Unknown filter field.', 'UNKNOWN_FILTER_FIELD'));

			return null;
		}

		return ['fieldId' => $fieldId, 'items' => $items];
	}

	/**
	 * Fields some filter of the list fills: the one it is showing right now and every preset the user
	 * has saved. A preset is counted because applying it words its own chips out of the values of the
	 * fields, and the values of a preset the user opens are needed the moment it is applied.
	 *
	 * @param array{fields: object, rows: string[]} $currentFilter
	 * @return string[]
	 */
	private function getUsedFieldIds(SavedFilterStorage $storage, array $currentFilter): array
	{
		$presets = $storage->getSavedFilters()['presets'];

		return DeferredItems::usedFieldIds([
			$currentFilter['fields'],
			...array_column($presets, 'fields'),
		]);
	}

	public function getSavedFiltersAction(array $context = []): ?array
	{
		return $this->getStorage($context)?->getSavedFilters();
	}

	/**
	 * The fields the form keeps, as the user composed them in the field selector. Stored where the
	 * web stores its own list, so the two forms of a list show one set of fields and a field added
	 * on the phone is still there after the app is restarted.
	 *
	 * @param string[] $rows
	 */
	public function saveFieldSetAction(array $rows = [], array $context = []): ?array
	{
		return $this->runOnStorage(
			$context,
			static fn(SavedFilterStorage $storage) => $storage->saveFieldSet($rows),
		);
	}

	public function saveFilterAction(
		string $presetId,
		array $context = [],
		string $name = '',
		array $fields = [],
		?array $rows = null,
	): ?array
	{
		return $this->runOnStorage(
			$context,
			static fn(SavedFilterStorage $storage) => $storage->save($presetId, $name, $fields, $rows),
		);
	}

	/**
	 * Order of the saved filters, as the user dragged them. The order is a value of the storage shared
	 * with the web (the `sort` of a preset), so the strip of this list reads the same order after a
	 * restart of the app and the web list shows it too.
	 *
	 * @param string[] $presetIds in the order the list is to show them
	 */
	public function reorderFiltersAction(array $presetIds, array $context = []): ?array
	{
		return $this->runOnStorage(
			$context,
			static fn(SavedFilterStorage $storage) => $storage->reorder($presetIds),
		);
	}

	public function deleteFilterAction(string $presetId, array $context = []): ?array
	{
		return $this->runOnStorage($context, static fn(SavedFilterStorage $s) => $s->delete($presetId));
	}

	public function pinFilterAction(string $presetId, array $context = []): ?array
	{
		return $this->runOnStorage($context, static fn(SavedFilterStorage $s) => $s->pin($presetId));
	}

	public function setForAllAction(string $presetId, array $context = []): ?array
	{
		return $this->runOnStorage($context, static fn(SavedFilterStorage $s) => $s->setForAll($presetId));
	}

	public function unsetForAllAction(string $presetId, array $context = []): ?array
	{
		return $this->runOnStorage($context, static fn(SavedFilterStorage $s) => $s->unsetForAll($presetId));
	}

	public function restoreFiltersAction(array $context = []): ?array
	{
		return $this->getStorage($context)?->restore();
	}

	/**
	 * @param callable(SavedFilterStorage): Result $operation
	 */
	private function runOnStorage(array $context, callable $operation): ?array
	{
		$storage = $this->getStorage($context);
		if ($storage === null)
		{
			return null;
		}

		$result = $operation($storage);
		if ($result->isSuccess())
		{
			return $result->getData();
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	private function getStorage(array $context): ?SavedFilterStorage
	{
		$list = $this->resolveListContext($context);

		return $list === null ? null : new SavedFilterStorage($list->getOptions());
	}

	private function resolveListContext(array $context): ?ListContext
	{
		$list = $this->createListContext($context);
		if ($list === null)
		{
			// the module says what was wrong with the context it could not read; a response
			// without a reason is worse than a generic one, so there is a fallback
			if (empty($this->getErrors()))
			{
				$this->addError(new Error('Unsupported list context.', 'UNSUPPORTED_LIST_CONTEXT'));
			}

			return null;
		}

		if (!$list->canRead())
		{
			$this->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return null;
		}

		return $list;
	}
}

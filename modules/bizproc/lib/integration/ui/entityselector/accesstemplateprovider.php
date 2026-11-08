<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Access\OwnTemplatesOption;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Service\DelegationScopeValidator;
use Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class AccessTemplateProvider extends BaseProvider
{
	public const ENTITY_ID = 'bizproc-access-template';

	/** First page size and the title threshold the page variables reuse. Search has a wider cap of its own. */
	public const ITEMS_LIMIT = 50;

	/**
	 * Search cap. Twice the number of matches the client renders (`SearchQuery.resultLimit` of the
	 * ui.entity-selector extension is 100), so it hides no visible match, yet it keeps a one letter query from
	 * scanning the whole template table on every keystroke.
	 */
	public const SEARCH_LIMIT = 200;

	/** Defensive cap on the query words a single search evaluates: bounds the WHERE clause a caller-supplied query can grow to. A well-formed search never needs more. */
	public const MAX_SEARCH_WORDS = 10;

	/** Defensive cap on the length of a single query word, matched against the SearchQuery::getQueryWords() input rather than the schema. */
	public const MAX_SEARCH_WORD_LENGTH = 255;

	/** Portion an id list is resolved by, so that no single WHERE IN grows past the size {@see self::SEARCH_LIMIT} allows. */
	public const RESOLVE_CHUNK_SIZE = self::SEARCH_LIMIT;

	/**
	 * Defensive cap on the ids resolved in one call (getItems() / getPreselectedItems()): five portions, far beyond
	 * any real role's grant list - a role that needs every template is stored as the "all" sentinel and not as an
	 * enumeration - so it only bounds a caller-supplied id array. Everything up to the cap is resolved.
	 */
	public const MAX_RESOLVE_IDS = 5 * self::RESOLVE_CHUNK_SIZE;

	/** The only item container rendered while the dialog is in dropdown mode. */
	private const TAB_ID = 'recents';

	private const OWN_TEMPLATES_PHRASE = 'BIZPROC_ACCESS_RIGHTS_OWN_TEMPLATES';

	/**
	 * Lang source of the sentinel caption, relative to the lib directory of the module. The phrase is shared with
	 * the page variables so the item never gets two different captions in one interface, and it therefore lives in
	 * the lang file of the permissions page provider. Loc resolves a phrase by the file that asks for it, so this
	 * one is loaded by hand: this file has no lang file of its own and must not grow a second copy of the phrase.
	 */
	private const OWN_TEMPLATES_PHRASE_SOURCE = '/Internal/Integration/UI/AccessRights/V2/AccessRightsProvider.php';

	private ?bool $templateAdmin = null;

	public function __construct(
		array $options = [],
		private readonly DelegationScopeValidator $scopeValidator = new DelegationScopeValidator(),
	)
	{
		parent::__construct();

		$this->options = $options;
	}

	/**
	 * Same gate as the permissions page: the portal product gate plus the configure-rights predicate. A
	 * denial makes {@see \Bitrix\UI\EntitySelector\Entity::create()} return null and the entity silently
	 * drops out of the dialog.
	 */
	final public function isAvailable(): bool
	{
		return $this->isFeatureEnabled() && $this->scopeValidator->canConfigure($this->getCurrentUserId());
	}

	final public function fillDialog(Dialog $dialog): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		// On top of the page, not in place of one of its templates: ITEMS_LIMIT stays the threshold of the
		// template page. The sentinel comes with the items and not from makeScopeQuery(), which is a query over
		// templates alone.
		if (OwnTemplatesOption::isEnabled())
		{
			$dialog->addItem($this->makeOwnTemplatesItem());
		}

		// addItems(), not addRecentItem(): the latter fills the recent collection of the dialog, which is the
		// very storage this entity stays out of. The recents tab comes from the `tabs` option of the item.
		$this->addItems($dialog, $this->templateQuery()->setLimit(self::ITEMS_LIMIT)->exec()->fetchAll());
	}

	final public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$words = $searchQuery->getQueryWords();
		if (!$words)
		{
			return;
		}

		$words = array_slice($words, 0, self::MAX_SEARCH_WORDS);

		$searchQuery->setCacheable(false); // required for dynamicSearchMatchMode: 'all'

		$this->addItems($dialog, $this->searchQuery($words)->exec()->fetchAll());
	}

	/**
	 * @param array<int, int|string> $ids
	 * @return Item[]
	 */
	final public function getItems(array $ids): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		// Before normalizeArrayValuesByInt(): it keeps positive values only and would drop the sentinel without a
		// word, leaving a granted right impossible to revoke - the dialog would never show the item picked.
		$items = $this->resolveOwnTemplatesItems($ids);

		Collection::normalizeArrayValuesByInt($ids, false);
		if (!$ids)
		{
			return $items;
		}

		return [
			...$items,
			...$this->makeItems($this->fetchTemplates(array_slice($ids, 0, self::MAX_RESOLVE_IDS))),
		];
	}

	/**
	 * Overridden to keep the deprecated getSelectedItems() out of the load path: Dialog::loadPreselectedItems()
	 * asks for preselected items, and the base implementation routes that through the deprecated method.
	 *
	 * @param array<int, int|string> $ids
	 * @return Item[]
	 */
	final public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	/**
	 * Belt and braces for an item assembled elsewhere: without a saveable flag the dialog would write the
	 * template id into the recent usage storage.
	 */
	final public function handleBeforeItemSave(Item $item): void
	{
		$item->setSaveable(false);
	}

	/**
	 * Title postfix of a template id, shared with the page variables so one template never gets two different
	 * captions in one interface.
	 */
	public static function makeTitlePostfix(int $id): string
	{
		return sprintf(' [%s]', $id);
	}

	/**
	 * Caption of the "own templates" sentinel, shared with the page variables so the item never gets two
	 * different captions in one interface. The phrase lives in the lang file of the permissions page provider
	 * and Loc resolves a phrase by the file that asks for it, so that file is loaded by hand here.
	 */
	public static function makeOwnTemplatesTitle(): string
	{
		Loc::loadLanguageFile(dirname(__DIR__, 3) . self::OWN_TEMPLATES_PHRASE_SOURCE);

		return (string)Loc::getMessage(self::OWN_TEMPLATES_PHRASE);
	}

	/**
	 * The domain filter of the permissions page, shared with the page variables so the dialog and the page
	 * never disagree on which templates exist. Mandatory in every query over that scope: a legacy, system or
	 * non-Nodes id may sit in b_bp_access_permission (there is no server-side validation of the saved ids),
	 * and without the filter such an id would pass as resolvable and lend its title to the options.
	 */
	public static function makeScopeQuery(): Query
	{
		return WorkflowTemplateTable::query()
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
		;
	}

	protected function isFeatureEnabled(): bool
	{
		return PermissionMatrixProvider::isConfigurationFeatureEnabled();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * Whether template ids are visible in captions. Not the isAvailable() gate: that one decides whether the
	 * entity is offered at all. Non-static so that a test can substitute the predicate.
	 */
	protected function isTemplateAdmin(int $userId): bool
	{
		return (new \CBPWorkflowTemplateUser($userId))->isAdmin();
	}

	/**
	 * Search is deliberately not cut back to the first page size, the way the neighbouring {@see TemplateProvider}
	 * does it: the first page is the cheap default, and refining the query is the only way to a template past it -
	 * a page sized cap would hide the very matches the administrator has just narrowed down to. What bounds it is
	 * {@see self::SEARCH_LIMIT}, set above everything the client renders and there only to break off the full
	 * scan a one letter query would otherwise run on every keystroke.
	 *
	 * Every word of the query is matched separately, because getQuery() glues the words back with spaces and
	 * "Deal approval" would then never reach "Deal renewal approval". The words are required together, so each
	 * of them comes as a subtree of its own: a single OR over the whole query would dissolve the rest of it.
	 *
	 * A match by id alone is not privileged over the matches by name: the result is ordered by id descending and
	 * cut at {@see self::SEARCH_LIMIT}, so the row the exact id found can fall past the cap once that many names
	 * hold the same digits.
	 *
	 * @param string[] $words
	 */
	private function searchQuery(array $words): Query
	{
		$query = $this->templateQuery()->setLimit(self::SEARCH_LIMIT);
		$searchesById = $this->isCurrentUserTemplateAdmin();

		foreach ($words as $word)
		{
			$word = mb_substr($word, 0, self::MAX_SEARCH_WORD_LENGTH);
			$query->where($this->wordFilter($word, $searchesById));
		}

		return $query;
	}

	/** A template admin sees the id in the caption, so a whole-number word reaches the template by its id as well. */
	private function wordFilter(string $word, bool $searchesById): ConditionTree
	{
		$filter = Query::filter()->logic('or')->whereLike('NAME', '%' . $word . '%');

		return $searchesById && $this->isIdWord($word) ? $filter->where('ID', (int)$word) : $filter;
	}

	/**
	 * A word stands for an id only as long as the cast to int reads back as the very same string. A fraction, an
	 * exponent, a leading zero and a run past PHP_INT_MAX all fail that: each of them would look a template up by
	 * a number the user never typed.
	 */
	private function isIdWord(string $word): bool
	{
		return (string)(int)$word === $word;
	}

	private function isCurrentUserTemplateAdmin(): bool
	{
		$this->templateAdmin ??= $this->isTemplateAdmin($this->getCurrentUserId());

		return $this->templateAdmin;
	}

	private function templateQuery(): Query
	{
		return self::makeScopeQuery()
			->setSelect(['ID', 'NAME'])
			->setOrder(['ID' => 'DESC'])
		;
	}

	/**
	 * The whole capped list is resolved, portion by portion: an id left unresolved reaches the matrix as a bare
	 * number instead of a template name. Sorted beforehand, so the portions together keep the order of a single query.
	 *
	 * @param int[] $ids
	 * @return array<int, array{ID: int|string, NAME: string|null}>
	 */
	private function fetchTemplates(array $ids): array
	{
		rsort($ids);

		$rows = [];
		foreach (array_chunk($ids, self::RESOLVE_CHUNK_SIZE) as $chunk)
		{
			$rows = array_merge($rows, $this->templateQuery()->whereIn('ID', $chunk)->exec()->fetchAll());
		}

		return $rows;
	}

	/**
	 * @param array<int, array{ID: int|string, NAME: string|null}> $rows
	 */
	private function addItems(Dialog $dialog, array $rows): void
	{
		$items = $this->makeItems($rows);
		if ($items)
		{
			$dialog->addItems($items);
		}
	}

	/**
	 * @param array<int, array{ID: int|string, NAME: string|null}> $rows
	 * @return Item[]
	 */
	private function makeItems(array $rows): array
	{
		$withPostfix = $this->isCurrentUserTemplateAdmin();

		$items = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$postfix = $withPostfix ? self::makeTitlePostfix($id) : '';

			$items[] = $this->makeItem($id, (string)$row['NAME'] . $postfix);
		}

		return $items;
	}

	private function makeItem(int $id, string $title): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => static::ENTITY_ID,
			'title' => $title,
			'tabs' => [self::TAB_ID],
			'saveable' => false,
		]);
	}

	/**
	 * The sentinel of the "own templates" scope, shaped after the "all" item of the rights matrix. Not built by
	 * makeItem(): the id is a scope value and not a template id, so it carries no id postfix and stays out of
	 * search results.
	 */
	private function makeOwnTemplatesItem(): Item
	{
		return new Item([
			'id' => PermissionDictionary::VALUE_VARIATION_OWN,
			'entityId' => static::ENTITY_ID,
			'title' => self::makeOwnTemplatesTitle(),
			'tabs' => [self::TAB_ID],
			'saveable' => false,
			'searchable' => false,
		]);
	}

	/**
	 * The sentinel resolved out of a preselected value list. It is not a template, so it never reaches the
	 * template lookup and is never replaced by the placeholder of a missing template.
	 *
	 * @param array<int, int|string> $ids
	 * @return Item[]
	 */
	private function resolveOwnTemplatesItems(array $ids): array
	{
		if (!OwnTemplatesOption::isEnabled())
		{
			return [];
		}

		$granted = in_array(
			PermissionDictionary::VALUE_VARIATION_OWN,
			array_map(static fn ($id): int => (int)$id, $ids),
			true,
		);

		return $granted ? [$this->makeOwnTemplatesItem()] : [];
	}
}

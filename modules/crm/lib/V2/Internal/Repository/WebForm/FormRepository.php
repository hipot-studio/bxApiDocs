<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormFilter;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormListSort;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\AgreementSectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\DependencySectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\FieldSectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\IntegrationSectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\PresetFieldSectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\ResponsibleSectionLoader;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\WebFormSectionLoader;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Internals\QueueTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\DateTimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

/**
 * @internal
 */
final class FormRepository
{
	private const MAX_PAGE_SIZE = 1000;

	private const OPERATOR_IN = 'in';

	private const BASE_COLUMN_BY_FIELD = [
		WebForm::FIELD_ID => 'ID',
		WebForm::FIELD_CODE => 'CODE',
		WebForm::FIELD_NAME => 'NAME',
		WebForm::FIELD_ACTIVE => 'ACTIVE',
		WebForm::FIELD_SYSTEM => 'IS_SYSTEM',
		WebForm::FIELD_LANGUAGE_ID => 'LANGUAGE_ID',
		WebForm::FIELD_TEMPLATE_ID => 'TEMPLATE_ID',
		WebForm::FIELD_CREATED_TIME => 'DATE_CREATE',
		WebForm::FIELD_MODIFIED_TIME => 'DATE_MODIFY',
		WebForm::FIELD_ACTIVE_CHANGED_BY_ID => 'ACTIVE_CHANGE_BY',
		WebForm::FIELD_ACTIVE_CHANGED_TIME => 'ACTIVE_CHANGE_DATE',
	];

	private const SECTION_COLUMN_BY_PATH = [
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_TITLE => 'CAPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_DESCRIPTION => 'DESCRIPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_CAPTION => 'BUTTON_CAPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_TEXT_COLOR => 'BUTTON_COLOR_FONT',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR => 'BUTTON_COLOR_BG',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BACKGROUND_IMAGE_ID => 'BACKGROUND_IMAGE',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_COPYRIGHT_REMOVED => 'COPYRIGHT_REMOVED',
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME => 'ENTITY_SCHEME',
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_DUPLICATE_MODE => 'DUPLICATE_MODE',
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_USER_IDS => 'ASSIGNED_BY_ID',
		WebForm::SECTION_AGREEMENTS . '.' . Section\Agreements::FIELD_ENABLED => 'USE_LICENCE',
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_SUCCESS
			. '.' . Section\SubmissionResult::FIELD_URL => 'RESULT_SUCCESS_URL',
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_SUCCESS
			. '.' . Section\SubmissionResult::FIELD_TEXT => 'RESULT_SUCCESS_TEXT',
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_FAILURE
			. '.' . Section\SubmissionResult::FIELD_URL => 'RESULT_FAILURE_URL',
		WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_FAILURE
			. '.' . Section\SubmissionResult::FIELD_TEXT => 'RESULT_FAILURE_TEXT',
		WebForm::SECTION_PAYMENT . '.' . Section\Payment::FIELD_ENABLED => 'IS_PAY',
		WebForm::SECTION_CALLBACK . '.' . Section\Callback::FIELD_ENABLED => 'IS_CALLBACK_FORM',
		WebForm::SECTION_CALLBACK . '.' . Section\Callback::FIELD_FROM => 'CALL_FROM',
		WebForm::SECTION_CALLBACK . '.' . Section\Callback::FIELD_TEXT => 'CALL_TEXT',
		WebForm::SECTION_BOOKING . '.' . Section\Booking::FIELD_ENABLED => 'IS_BOOKING_FORM',
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_GOOGLE_ANALYTICS_ID => 'GOOGLE_ANALYTICS_ID',
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED
			=> 'GOOGLE_ANALYTICS_PAGE_VIEW',
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_YANDEX_METRICA_ID => 'YANDEX_METRIC_ID',
		WebForm::SECTION_SPAM_PROTECTION . '.' . Section\SpamProtection::FIELD_ENABLED => 'USE_CAPTCHA',
		WebForm::SECTION_INTEGRATIONS . '.' . Section\Integrations::FIELD_WHATS_APP_ENABLED => 'IS_WHATSAPP_FORM',
	];

	/** The direct way of building a condition: the column the field is read by, compared as it is. */
	private const WAY_COLUMN = 'column';

	/**
	 * The way of a column compared without regard to case: both sides of the comparison go through
	 * one and the same function of the database, so the answer stops depending on the settings of
	 * comparison of the column. Those settings decide it differently on every supported database -
	 * a case-insensitive collation of MySQL answers a name differing only in case as the same name,
	 * and PostgreSQL under `lc_collate = en_US.utf8` answers it as a different one - while the
	 * published contract promises one behaviour for all of them.
	 *
	 * The case is folded by the database and never in PHP: PostgreSQL folds by `lc_collate` and
	 * MySQL by the encoding of the column, so a value folded by the rules of PHP would compare
	 * unequal to a column the database itself considers equal to it.
	 */
	private const WAY_CASE_INSENSITIVE_COLUMN = 'caseInsensitiveColumn';

	/**
	 * The way of the version of the form: the condition is built over the value the answer shows -
	 * the stored version, and the date of creation while the version is empty - rather than over the
	 * column, so a filter and the answer to it cannot disagree about a form whose version is empty.
	 */
	private const WAY_VERSION_VALUE = 'versionValue';

	/**
	 * The way of the users of a form: the condition asks whether a row exists in the queue of the
	 * responsible, joined by `or` with the legacy column of the form's own row - the same two sources
	 * the answer assembles the section out of ({@see self::mergeUserIds()}).
	 */
	private const WAY_RESPONSIBLE_SUBQUERY = 'responsibleSubquery';

	/** The name a query registers the computed version of the form under. */
	private const VERSION_VALUE_FIELD = 'VERSION_VALUE';

	/**
	 * The function both sides of a comparison without regard to case go through. One constant holds
	 * it for both sides on purpose: the column and the value stop answering the same question the
	 * moment they are folded by two different functions.
	 */
	private const CASE_FOLDING_FUNCTION = 'UPPER';

	/** The suffix a query registers the folded form of a column under. */
	private const CASE_FOLDED_COLUMN_SUFFIX = '_CASE_FOLDED';

	/**
	 * How the condition of a field of the filter is built. The map answers "in which way", not "by
	 * which column": the column of the direct way is the one the read maps above already declare, so
	 * one field is described by a column once and cannot end up described by two.
	 *
	 * A published field of the filter that has no way here throws instead of being quietly left out
	 * of the statement - a guard against a mistake of the caller rather than a refusal answered to a
	 * client: the transport does not turn this exception into one, so a request that reached it would
	 * be answered a system error. Nothing a client sends reaches it: the white lists of the transport
	 * - the attributes of the published contract - and of the domain
	 * ({@see WebFormFilter::getOperatorsByField()}, {@see WebFormListSort::getSortableFields()})
	 * refuse such a field first, and the invariants of the storage hold the layers to saying the same
	 * thing ({@see \Bitrix\Crm\Tests\Integration\Lib\V2\Internal\Repository\WebForm\FormRepositoryTest}).
	 * The order ({@see self::requireSortExpression()}) and the users of a condition of the
	 * responsible ({@see self::requireUserIds()}) are guarded the same way.
	 */
	private const CONDITION_WAY_BY_FIELD = [
		WebForm::FIELD_ID => self::WAY_COLUMN,
		WebForm::FIELD_CODE => self::WAY_COLUMN,
		WebForm::FIELD_NAME => self::WAY_CASE_INSENSITIVE_COLUMN,
		WebForm::FIELD_ACTIVE => self::WAY_COLUMN,
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME => self::WAY_COLUMN,
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_USER_IDS
			=> self::WAY_RESPONSIBLE_SUBQUERY,
		WebForm::FIELD_CREATED_TIME => self::WAY_COLUMN,
		WebForm::FIELD_MODIFIED_TIME => self::WAY_VERSION_VALUE,
	];

	/** @var array<string, WebFormSectionLoader> */
	private array $sectionLoadersByName = [];

	/**
	 * Creates a repository with default or injected section loaders.
	 *
	 * @param null|array<WebFormSectionLoader> $sectionLoaders
	 */
	public function __construct(?array $sectionLoaders = null)
	{
		$sectionLoaders ??= self::createDefaultSectionLoaders();
		foreach ($sectionLoaders as $loader)
		{
			$sectionName = $loader->getSectionName();
			if (isset($this->sectionLoadersByName[$sectionName]))
			{
				throw new InvalidArgumentException(sprintf('Duplicate web form section loader `%s`', $sectionName));
			}

			$this->sectionLoadersByName[$sectionName] = $loader;
		}
	}

	/**
	 * Returns a default CRM form with the requested projection.
	 *
	 * @param int $id
	 * @param WebFormSelect $select
	 * @return WebForm|null
	 */
	public function getById(int $id, WebFormSelect $select): ?WebForm
	{
		$row =
			FormTable::query()
				->setSelect(self::getColumns($select))
				->where('ID', $id)
				->where('TYPE_ID', FormTable::TYPE_DEFAULT)
				->setLimit(1)
				->fetch()
		;
		if (!is_array($row))
		{
			return null;
		}

		$formId = (int)$row['ID'];
		$loadedSections = $this->loadChildSections([$formId], $select);

		return self::createWebForm($row, $select, $loadedSections);
	}

	/**
	 * Returns a stable page of default CRM forms with the requested projection.
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function findAll(
		WebFormFilter $filter,
		WebFormListSort $sort,
		int $limit,
		int $offset,
		WebFormSelect $select,
	): WebFormCollection
	{
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE)
		{
			throw new ArgumentException(sprintf('Invalid web form page limit `%d`', $limit), 'limit');
		}
		if ($offset < 0)
		{
			throw new ArgumentException(sprintf('Invalid web form page offset `%d`', $offset), 'offset');
		}

		$query = FormTable::query()->setSelect(self::getColumns($select));
		if (self::needsVersionValue($filter, $sort))
		{
			$query->registerRuntimeField(self::createVersionValueField());
		}
		if ($filter->hasConditions())
		{
			$query->where(self::translateFilter($filter));
		}

		$rows = $query
			->where('TYPE_ID', FormTable::TYPE_DEFAULT)
			->setOrder(self::translateSort($sort))
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;
		if ($rows === [])
		{
			return new WebFormCollection();
		}

		$formIds = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		$loadedSections = $this->loadChildSections($formIds, $select);
		$forms = [];
		foreach ($rows as $row)
		{
			$forms[] = self::createWebForm($row, $select, $loadedSections);
		}

		return new WebFormCollection(...$forms);
	}

	/**
	 * How many forms of the portal are working right now.
	 *
	 * This is the number the maximum below is compared with, so it counts what the product counts
	 * ({@see Form::canActivateForm()}) rather than what the projections above read: system forms
	 * are left out because they are turned on beside the limit, and no type of form is left out
	 * because the limit has never been about one type.
	 */
	public function getActiveFormCount(): int
	{
		return max(0, FormTable::getCount([
			'=ACTIVE' => 'Y',
			'=IS_SYSTEM' => 'N',
		]));
	}

	/**
	 * How many forms the portal may keep working at once.
	 *
	 * The option carries the maximum the product itself compares with, its default included, and
	 * asking the legacy declaration for it keeps the name of the option and that default spelled
	 * out once ({@see Form::getMaxActivatedFormLimit()}). Whether the maximum applies at all is a
	 * question of the plan and is answered by the integration layer, not here.
	 */
	public function getActiveFormMaximum(): int
	{
		return max(0, (int)Form::getMaxActivatedFormLimit());
	}

	/**
	 * @throws ArgumentException
	 */
	private static function translateFilter(WebFormFilter $filter): ConditionTree|Condition
	{
		if ($filter->isCondition())
		{
			return self::translateCondition($filter);
		}

		$tree = (new ConditionTree())
			->logic($filter->getLogic())
			->negative($filter->isNegative())
		;
		$merged = [];
		foreach ($filter->getConditions() as $condition)
		{
			$key = self::mergeKeyOf($condition);
			if ($key !== null)
			{
				if (isset($merged[$key]))
				{
					continue;
				}

				$merged[$key] = true;
			}

			$tree->addCondition(self::translateFilter($condition));
		}

		return $tree;
	}

	/**
	 * The key two nodes standing side by side are one node by, or `null` for a node that is left as
	 * it was written. A node joins what it holds by `and` or by `or`, and both read a repeat as the
	 * thing itself, so a repeat may be dropped - but only where dropping it is worth the reading of
	 * this key: a condition by the responsible carries a whole pass over the queue of the
	 * responsible, while every other way builds a predicate over a column of the row and a repeat of
	 * it costs the statement its length alone.
	 *
	 * The operator is not part of the key: the users are what the condition is built out of, and one
	 * user given to an equality and the same user given to an enumeration build the same condition.
	 *
	 * @throws ArgumentException
	 */
	private static function mergeKeyOf(WebFormFilter $node): ?string
	{
		$condition = self::unwrapSingleCondition($node);
		if ($condition === null)
		{
			return null;
		}

		$field = $condition->getField();
		$operator = $condition->getOperator();
		if ($field === null || $operator === null)
		{
			return null;
		}

		if (self::requireConditionWay($field) !== self::WAY_RESPONSIBLE_SUBQUERY)
		{
			return null;
		}

		$value = $condition->getValue();
		if ($operator === self::OPERATOR_IN && $value === [])
		{
			// An empty enumeration builds no subquery at all, so there is nothing to merge.
			return null;
		}

		$userIds = self::requireUserIds($operator, $value);
		sort($userIds);

		return $field . ':' . implode(',', $userIds);
	}

	/**
	 * The condition a node stands for, or `null` when it stands for more than one. A group that
	 * joins a single condition and negates nothing is that condition: neither `and` nor `or` of one
	 * operand says anything. The unwrapping is what the filter of a request needs rather than a
	 * convenience - the transport hands the domain exactly such a group around every condition it
	 * read ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper}).
	 *
	 * A negating group is left alone: it says the opposite of the condition it holds, and telling
	 * the two apart by this key is not what the key is for.
	 */
	private static function unwrapSingleCondition(WebFormFilter $node): ?WebFormFilter
	{
		while (!$node->isCondition())
		{
			$conditions = $node->getConditions();
			if ($node->isNegative() || count($conditions) !== 1)
			{
				return null;
			}

			$node = $conditions[0];
		}

		return $node;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function translateCondition(WebFormFilter $condition): ConditionTree|Condition
	{
		$field = $condition->getField();
		$operator = $condition->getOperator();
		if ($field === null || $operator === null)
		{
			throw new ArgumentException('Invalid web form filter condition', 'filter');
		}

		$way = self::requireConditionWay($field);
		$value = $condition->getValue();
		if ($operator === self::OPERATOR_IN)
		{
			$value = is_array($value) ? self::uniqueValues($value) : $value;
			if ($value === [])
			{
				return self::createAlwaysFalseCondition();
			}
		}

		return match ($way)
		{
			self::WAY_COLUMN => new Condition(self::requireColumnOf($field), $operator, $value),
			self::WAY_CASE_INSENSITIVE_COLUMN => self::createCaseInsensitiveCondition($field, $operator, $value),
			self::WAY_VERSION_VALUE => new Condition(self::VERSION_VALUE_FIELD, $operator, $value),
			self::WAY_RESPONSIBLE_SUBQUERY => self::createResponsibleCondition($field, $operator, $value),
		};
	}

	/**
	 * A comparison of a column that ignores case: the column is folded by a value of the query and
	 * every compared value by the very same function, so neither side of the comparison carries a
	 * case of its own into the answer.
	 *
	 * The value is folded by the database rather than by PHP, and this is what the two sides being
	 * one function buys: the rules of folding differ between the databases and between their
	 * encodings, so there is no rule of PHP that could be relied on to agree with them.
	 *
	 * The folded column is declared as a value of the query only for a read whose condition asks for
	 * it, and the order by the same field keeps naming the column
	 * ({@see self::requireSortExpression()}): the contract promises nothing about case in the order,
	 * and a key over a value rather than over a column would order a page differently for nothing.
	 */
	private static function createCaseInsensitiveCondition(
		string $field,
		string $operator,
		mixed $value,
	): Condition
	{
		$column = self::requireColumnOf($field);
		$foldedColumn = (new ExpressionField(
			$column . self::CASE_FOLDED_COLUMN_SUFFIX,
			self::CASE_FOLDING_FUNCTION . '(%s)',
			[$column],
		))->configureValueType(StringField::class);

		return new Condition(
			$foldedColumn,
			$operator,
			is_array($value)
				? array_map(static fn(mixed $item): SqlExpression => self::foldCaseOfValue($item), $value)
				: self::foldCaseOfValue($value),
		);
	}

	/**
	 * One compared value with its case folded by the database. An enumeration is folded value by
	 * value, because value by value is how the query builder reads the value of an enumeration
	 * ({@see ConditionTree::convertValues()}).
	 */
	private static function foldCaseOfValue(mixed $value): SqlExpression
	{
		return new SqlExpression(self::CASE_FOLDING_FUNCTION . '(?s)', $value);
	}

	/**
	 * The version of the form as a value of the query: the stored one, and the date of creation while
	 * it is empty. The function of the substitution comes from the abstraction of the core, so the
	 * expression holds on every supported database; the type of the value is declared, or the moment
	 * of a condition would be written in the format of the site instead of the one of the database.
	 */
	private static function createVersionValueField(): ExpressionField
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();

		return (new ExpressionField(
			self::VERSION_VALUE_FIELD,
			$sqlHelper->getIsNullFunction('%s', '%s'),
			[
				self::requireColumnOf(WebForm::FIELD_MODIFIED_TIME),
				self::requireColumnOf(WebForm::FIELD_CREATED_TIME),
			],
		))->configureValueType(DateTimeField::class);
	}

	/**
	 * The order the storage sets: the keys the mapper handed over, in that order, each resolved to what
	 * the query knows the field by.
	 *
	 * @return array<string, string>
	 * @throws ArgumentException
	 */
	private static function translateSort(WebFormListSort $sort): array
	{
		$order = [];
		foreach ($sort->getDirectionByField() as $field => $direction)
		{
			$order[self::requireSortExpression($field)] = $direction;
		}

		return $order;
	}

	/**
	 * What an order key names in the query. The map of the ways answers this too, but it answers it
	 * with the column wherever the field has one: the two ways over a column order by that column,
	 * the way of the version orders by the computed value, and the way of the responsible builds a
	 * whole predicate rather than a value and orders nothing - so a field ordered that way is
	 * refused here, along with a field the map does not carry at all.
	 *
	 * A condition that ignores case does not make the order ignore it: the published contract
	 * promises nothing about case in the order, and folding a key would cost every ordered read a
	 * value where a column serves.
	 *
	 * @throws ArgumentException
	 */
	private static function requireSortExpression(string $field): string
	{
		return match (self::CONDITION_WAY_BY_FIELD[$field] ?? null)
		{
			self::WAY_COLUMN, self::WAY_CASE_INSENSITIVE_COLUMN => self::requireColumnOf($field),
			self::WAY_VERSION_VALUE => self::VERSION_VALUE_FIELD,
			default => throw new ArgumentException(
				sprintf('Unknown web form sort field `%s`', $field),
				'order',
			),
		};
	}

	/**
	 * Whether the query needs the computed version declared. ALG-02 declares it when a condition or a
	 * key of the order is built over it, and never otherwise: a read that asks for it in neither place
	 * keeps the statement it had before the value existed, which is what leaves a future index over
	 * the column usable.
	 */
	private static function needsVersionValue(WebFormFilter $filter, WebFormListSort $sort): bool
	{
		return self::filterNeedsVersionValue($filter) || self::sortNeedsVersionValue($sort);
	}

	/** Whether any key of the order is built over the computed version of the form. */
	private static function sortNeedsVersionValue(WebFormListSort $sort): bool
	{
		foreach (array_keys($sort->getDirectionByField()) as $field)
		{
			if ((self::CONDITION_WAY_BY_FIELD[$field] ?? null) === self::WAY_VERSION_VALUE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any condition of the filter is built over the computed version of the form. The map of
	 * the ways is asked rather than a list of field names of its own, so the query gets the computed
	 * value exactly when a condition needs it.
	 */
	private static function filterNeedsVersionValue(WebFormFilter $filter): bool
	{
		if ($filter->isCondition())
		{
			$field = $filter->getField();

			return $field !== null
				&& (self::CONDITION_WAY_BY_FIELD[$field] ?? null) === self::WAY_VERSION_VALUE;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if (self::filterNeedsVersionValue($condition))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The users of a form, from the two sources the answer assembles the section out of: the legacy
	 * column of the form's own row and the queue of the responsible. The queue is read by an
	 * uncorrelated subquery rather than joined - it allows one user more than once in one form, and a
	 * join would answer such a form as several rows, which breaks both the page and the batch read of
	 * the sections.
	 *
	 * One subquery is one pass over the queue, and the number of them is what the filter decides, so
	 * two conditions that would build the same subquery build it once ({@see self::mergeKeyOf()}) and
	 * a user named twice inside one condition is named once in the statement
	 * ({@see self::requireUserIds()}).
	 *
	 * The check over the legacy column asks whether the column holds a value before comparing it, so
	 * that the whole condition answers either true or false and nothing else. The column is nullable
	 * and carries no value in the forms of a portal today: a comparison alone would answer such a row
	 * with neither, and a group that negates this condition would answer it with neither as well -
	 * dropping a form that belongs to the page of "not this user" out of it.
	 *
	 * @throws ArgumentException
	 */
	private static function createResponsibleCondition(
		string $field,
		string $operator,
		mixed $value,
	): ConditionTree
	{
		$userIds = self::requireUserIds($operator, $value);
		$column = self::requireColumnOf($field);
		$formsOfTheQueue = QueueTable::query()
			->setSelect(['FORM_ID'])
			->whereIn('USER_ID', $userIds)
		;

		return (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where(
				(new ConditionTree())
					->whereNotNull($column)
					->whereIn($column, $userIds)
			)
			->whereIn('ID', $formsOfTheQueue)
		;
	}

	/**
	 * The users a condition of the responsible narrows by: the whole list for an enumeration, the one
	 * user for every other operator. An identifier that is not positive is refused - the answer drops
	 * such identifiers from both sources, so a condition that took one would narrow by a user no form
	 * is ever answered with. The transport refuses it first; this is the domain refusing it again.
	 *
	 * A user named more than once narrows the page by exactly what the same user named once narrows
	 * it by, so the list reaches the two enumerations of the condition without repeats.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 */
	private static function requireUserIds(string $operator, mixed $value): array
	{
		$userIds = $operator === self::OPERATOR_IN ? $value : [$value];
		if (!is_array($userIds) || $userIds === [])
		{
			throw new ArgumentException('Empty web form responsible filter value', 'filter');
		}

		foreach ($userIds as $userId)
		{
			if (!is_int($userId) || $userId <= 0)
			{
				throw new ArgumentException(
					sprintf('Unsupported web form responsible identifier `%s`', get_debug_type($userId)),
					'filter',
				);
			}
		}

		return array_values(array_unique($userIds));
	}

	/**
	 * The values of an enumeration without repeats: a repeated value narrows the page by nothing and
	 * costs the statement its length. Values of different types are kept apart - which of them are one
	 * value is decided by the type of the column they are compared against, and this is not the place
	 * to decide it.
	 *
	 * @return array<int, mixed>
	 */
	private static function uniqueValues(array $values): array
	{
		$unique = [];
		$seen = [];
		foreach ($values as $value)
		{
			$key = get_debug_type($value) . ':' . serialize($value);
			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$unique[] = $value;
		}

		return $unique;
	}

	/**
	 * An empty `in` set has no valid SQL form, so it is translated into a predicate that
	 * matches nothing instead of reaching the query builder.
	 */
	private static function createAlwaysFalseCondition(): Condition
	{
		return new Condition('ID', '=', 0);
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireConditionWay(string $field): string
	{
		$way = self::CONDITION_WAY_BY_FIELD[$field] ?? null;
		if ($way === null)
		{
			throw new ArgumentException(sprintf('Unknown web form filter field `%s`', $field), 'filter');
		}

		return $way;
	}

	/**
	 * The column the field is read by, taken from the maps of the projection so that no field is
	 * described by a column twice - here or in the expression a computed value is built from. A field
	 * the maps do not declare is a mistake of the caller, not anything a request can ask for.
	 */
	private static function requireColumnOf(string $field): string
	{
		$column = self::BASE_COLUMN_BY_FIELD[$field] ?? self::SECTION_COLUMN_BY_PATH[$field] ?? null;
		if ($column === null)
		{
			throw new InvalidArgumentException(sprintf('Missing web form column of field `%s`', $field));
		}

		return $column;
	}

	/**
	 * @return WebFormSectionLoader[]
	 */
	private static function createDefaultSectionLoaders(): array
	{
		return [
			new FieldSectionLoader(),
			new DependencySectionLoader(),
			new PresetFieldSectionLoader(),
			new ResponsibleSectionLoader(),
			new AgreementSectionLoader(),
			new IntegrationSectionLoader(),
		];
	}

	/**
	 * @return string[]
	 */
	private static function getColumns(WebFormSelect $select): array
	{
		$columns = ['ID'];
		foreach (self::BASE_COLUMN_BY_FIELD as $field => $column)
		{
			if ($select->hasBaseField($field))
			{
				$columns[] = $column;
			}
		}

		if ($select->hasBaseField(WebForm::FIELD_MODIFIED_TIME))
		{
			// The version falls back to the creation time while the column is empty, so the
			// column of the fallback comes along whether it was asked for or not
			$columns[] = 'DATE_CREATE';
		}

		foreach (self::SECTION_COLUMN_BY_PATH as $path => $column)
		{
			[$section, $field] = explode('.', $path, 2);
			if (
				$select->hasSection($section)
				&& self::isLeafSelected($select->getSectionFields($section), $field)
			)
			{
				$columns[] = $column;
			}
		}

		// Paths stored in a sub key of the settings column have no column of their own and are
		// absent from the maps above: FormSettingsColumn maps them for the read and the write at
		// once. The column comes along whole as soon as a single leaf of it is asked for, and only
		// then.
		foreach (FormSettingsColumn::paths() as $path)
		{
			[$section, $field] = explode('.', $path, 2);
			if (
				$select->hasSection($section)
				&& self::isLeafSelected($select->getSectionFields($section), $field)
			)
			{
				$columns[] = FormSettingsColumn::COLUMN;

				break;
			}
		}

		$agreementFields = $select->getSectionFields(WebForm::SECTION_AGREEMENTS);
		if (self::hasSelectedPath($agreementFields, Section\Agreements::FIELD_ITEMS))
		{
			$columns[] = 'AGREEMENT_ID';
			$columns[] = 'LICENCE_BUTTON_IS_CHECKED';
		}

		return array_values(array_unique($columns));
	}

	/**
	 * @param int[] $formIds
	 * @return array<string, array<int, mixed>>
	 */
	private function loadChildSections(array $formIds, WebFormSelect $select): array
	{
		$result = [];
		foreach ($select->getChildSectionNames() as $sectionName)
		{
			$loader = $this->sectionLoadersByName[$sectionName] ?? null;
			if ($loader === null)
			{
				throw new InvalidArgumentException(sprintf('Missing web form section loader `%s`', $sectionName));
			}

			$result[$sectionName] = $loader->load(
				$formIds,
				self::getLoaderSelect($sectionName, $select),
			);
		}

		return $result;
	}

	private static function getLoaderSelect(string $sectionName, WebFormSelect $select): WebFormSelect
	{
		if ($sectionName !== WebForm::SECTION_AGREEMENTS)
		{
			return $select;
		}

		$fields = $select->getSectionFields($sectionName);
		$itemIdPath = Section\Agreements::FIELD_ITEMS . '.' . Section\AgreementItem::FIELD_AGREEMENT_ID;
		if (!self::hasSelectedPath($fields, Section\Agreements::FIELD_ITEMS) || self::isLeafSelected($fields, $itemIdPath))
		{
			return $select;
		}

		return WebFormSelect::fromPaths([
			...$select->getPaths(),
			WebForm::SECTION_AGREEMENTS . '.' . $itemIdPath,
		]);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, array<int, mixed>> $loadedSections
	 */
	private static function createWebForm(array $row, WebFormSelect $select, array $loadedSections): WebForm
	{
		$formId = (int)$row['ID'];

		return new WebForm(
			id: $formId,
			code: self::nullableString($row, 'CODE', $select->hasBaseField(WebForm::FIELD_CODE)),
			name: self::string($row, 'NAME', $select->hasBaseField(WebForm::FIELD_NAME)),
			active: self::boolean($row, 'ACTIVE', $select->hasBaseField(WebForm::FIELD_ACTIVE)),
			system: self::boolean($row, 'IS_SYSTEM', $select->hasBaseField(WebForm::FIELD_SYSTEM)),
			languageId: self::nullableString(
				$row,
				'LANGUAGE_ID',
				$select->hasBaseField(WebForm::FIELD_LANGUAGE_ID),
			),
			templateId: self::nullableString(
				$row,
				'TEMPLATE_ID',
				$select->hasBaseField(WebForm::FIELD_TEMPLATE_ID),
			),
			createdTime: self::dateTime(
				$row,
				'DATE_CREATE',
				$select->hasBaseField(WebForm::FIELD_CREATED_TIME),
			),
			modifiedTime: self::modifiedTime(
				$row,
				$select->hasBaseField(WebForm::FIELD_MODIFIED_TIME),
			),
			activeChangedById: self::nullableInteger(
				$row,
				'ACTIVE_CHANGE_BY',
				$select->hasBaseField(WebForm::FIELD_ACTIVE_CHANGED_BY_ID),
			),
			activeChangedTime: self::dateTime(
				$row,
				'ACTIVE_CHANGE_DATE',
				$select->hasBaseField(WebForm::FIELD_ACTIVE_CHANGED_TIME),
			),
			appearance: self::createAppearance($row, $select),
			crm: self::createCrmSettings($row, $select),
			fields: self::getLoadedCollection($loadedSections, WebForm::SECTION_FIELDS, $formId, $select),
			dependencies: self::getLoadedCollection(
				$loadedSections,
				WebForm::SECTION_DEPENDENCIES,
				$formId,
				$select,
			),
			presetFields: self::getLoadedCollection(
				$loadedSections,
				WebForm::SECTION_PRESET_FIELDS,
				$formId,
				$select,
			),
			responsible: self::createResponsible($row, $loadedSections, $formId, $select),
			agreements: self::createAgreements($row, $loadedSections, $formId, $select),
			submission: self::createSubmission($row, $select),
			payment: self::createPayment($row, $select),
			callback: self::createCallback($row, $select),
			booking: self::createBooking($row, $select),
			analytics: self::createAnalytics($row, $select),
			spamProtection: self::createSpamProtection($row, $select),
			integrations: self::createIntegrations($row, $loadedSections, $formId, $select),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createAppearance(array $row, WebFormSelect $select): ?Section\Appearance
	{
		if (!$select->hasSection(WebForm::SECTION_APPEARANCE))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_APPEARANCE);

		return new Section\Appearance(
			title: self::nullableString($row, 'CAPTION', self::isLeafSelected($fields, Section\Appearance::FIELD_TITLE)),
			description: self::nullableString(
				$row,
				'DESCRIPTION',
				self::isLeafSelected($fields, Section\Appearance::FIELD_DESCRIPTION),
			),
			buttonCaption: self::nullableString(
				$row,
				'BUTTON_CAPTION',
				self::isLeafSelected($fields, Section\Appearance::FIELD_BUTTON_CAPTION),
			),
			buttonTextColor: self::nullableString(
				$row,
				'BUTTON_COLOR_FONT',
				self::isLeafSelected($fields, Section\Appearance::FIELD_BUTTON_TEXT_COLOR),
			),
			buttonBackgroundColor: self::nullableString(
				$row,
				'BUTTON_COLOR_BG',
				self::isLeafSelected($fields, Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR),
			),
			backgroundImageId: self::nullableInteger(
				$row,
				'BACKGROUND_IMAGE',
				self::isLeafSelected($fields, Section\Appearance::FIELD_BACKGROUND_IMAGE_ID),
			),
			copyrightRemoved: self::boolean(
				$row,
				'COPYRIGHT_REMOVED',
				self::isLeafSelected($fields, Section\Appearance::FIELD_COPYRIGHT_REMOVED),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createCrmSettings(array $row, WebFormSelect $select): ?Section\CrmSettings
	{
		if (!$select->hasSection(WebForm::SECTION_CRM))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_CRM);

		return new Section\CrmSettings(
			entityScheme: self::integer(
				$row,
				'ENTITY_SCHEME',
				self::isLeafSelected($fields, Section\CrmSettings::FIELD_ENTITY_SCHEME),
			),
			duplicateMode: self::nullableString(
				$row,
				'DUPLICATE_MODE',
				self::isLeafSelected($fields, Section\CrmSettings::FIELD_DUPLICATE_MODE),
			),
			dealCategoryId: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_CRM,
				Section\CrmSettings::FIELD_DEAL_CATEGORY_ID,
			),
			dealDuplicateControlEnabled: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_CRM,
				Section\CrmSettings::FIELD_DEAL_DUPLICATE_CONTROL_ENABLED,
			),
			smartProcessCategoryId: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_CRM,
				Section\CrmSettings::FIELD_SMART_PROCESS_CATEGORY_ID,
			),
			smartProcessDuplicateControlEnabled: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_CRM,
				Section\CrmSettings::FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED,
			),
		);
	}

	/**
	 * A setting stored in a sub key of the settings column, read by the field of the section holding
	 * it. The column owns the type of the value and the default the read answers with, so nothing of
	 * that is repeated here; the section below is what enforces the type.
	 *
	 * @param array<string, mixed> $row
	 * @param string[] $fields
	 */
	private static function settingsValue(array $row, array $fields, string $section, string $field): mixed
	{
		return FormSettingsColumn::value(
			$row[FormSettingsColumn::COLUMN] ?? null,
			$section . '.' . $field,
			self::isLeafSelected($fields, $field),
		);
	}

	/**
	 * @param array<string, array<int, mixed>> $loadedSections
	 * @return null|array<mixed>
	 */
	private static function getLoadedCollection(
		array $loadedSections,
		string $sectionName,
		int $formId,
		WebFormSelect $select,
	): ?array
	{
		if (!$select->hasSection($sectionName))
		{
			return null;
		}

		$items = $loadedSections[$sectionName][$formId] ?? [];

		return is_array($items) ? array_values($items) : [];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, array<int, mixed>> $loadedSections
	 */
	private static function createResponsible(
		array $row,
		array $loadedSections,
		int $formId,
		WebFormSelect $select,
	): ?Section\Responsible
	{
		if (!$select->hasSection(WebForm::SECTION_RESPONSIBLE))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_RESPONSIBLE);
		$loaded = $loadedSections[WebForm::SECTION_RESPONSIBLE][$formId] ?? null;
		$loaded = $loaded instanceof Section\Responsible ? $loaded : null;
		$userIds = null;
		if (self::isLeafSelected($fields, Section\Responsible::FIELD_USER_IDS))
		{
			$userIds = self::mergeUserIds(
				self::nullableInteger($row, 'ASSIGNED_BY_ID', true),
				$loaded?->userIds ?? [],
			);
		}

		return new Section\Responsible(
			userIds: $userIds,
			checkWorkTime: self::isLeafSelected($fields, Section\Responsible::FIELD_CHECK_WORK_TIME)
				? ($loaded?->checkWorkTime ?? false)
				: null,
		);
	}

	/**
	 * @param int[] $queueUserIds
	 * @return int[]
	 */
	private static function mergeUserIds(?int $assignedById, array $queueUserIds): array
	{
		$result = [];
		$seen = [];
		foreach ([$assignedById, ...$queueUserIds] as $userId)
		{
			$userId = (int)$userId;
			if ($userId <= 0 || isset($seen[$userId]))
			{
				continue;
			}

			$seen[$userId] = true;
			$result[] = $userId;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, array<int, mixed>> $loadedSections
	 */
	private static function createAgreements(
		array $row,
		array $loadedSections,
		int $formId,
		WebFormSelect $select,
	): ?Section\Agreements
	{
		if (!$select->hasSection(WebForm::SECTION_AGREEMENTS))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_AGREEMENTS);
		$loaded = $loadedSections[WebForm::SECTION_AGREEMENTS][$formId] ?? null;
		$loaded = $loaded instanceof Section\Agreements ? $loaded : null;
		$items = null;
		if (self::hasSelectedPath($fields, Section\Agreements::FIELD_ITEMS))
		{
			$items = self::mergeAgreementItems($row, $loaded?->items ?? [], $fields);
		}

		return new Section\Agreements(
			enabled: self::boolean(
				$row,
				'USE_LICENCE',
				self::isLeafSelected($fields, Section\Agreements::FIELD_ENABLED),
			),
			items: $items,
		);
	}

	/**
	 * Rows of AgreementTable replace the legacy item with the same agreement ID.
	 *
	 * @param array<string, mixed> $row
	 * @param Section\AgreementItem[] $loadedItems
	 * @param string[] $selectedFields
	 * @return Section\AgreementItem[]
	 */
	private static function mergeAgreementItems(array $row, array $loadedItems, array $selectedFields): array
	{
		$itemsByAgreementId = [];
		$legacyAgreementId = self::nullableInteger($row, 'AGREEMENT_ID', true);
		if ($legacyAgreementId !== null && $legacyAgreementId > 0)
		{
			$itemsByAgreementId[$legacyAgreementId] = self::createLegacyAgreementItem(
				$row,
				$legacyAgreementId,
				$selectedFields,
			);
		}

		foreach ($loadedItems as $item)
		{
			$agreementId = $item->agreementId;
			if ($agreementId === null || $agreementId <= 0)
			{
				continue;
			}

			$itemsByAgreementId[$agreementId] = new Section\AgreementItem(
				agreementId: self::isLeafSelected(
					$selectedFields,
					Section\Agreements::FIELD_ITEMS . '.' . Section\AgreementItem::FIELD_AGREEMENT_ID,
				) ? $agreementId : null,
				checked: self::isLeafSelected(
					$selectedFields,
					Section\Agreements::FIELD_ITEMS . '.' . Section\AgreementItem::FIELD_CHECKED,
				) ? $item->checked : null,
				required: self::isLeafSelected(
					$selectedFields,
					Section\Agreements::FIELD_ITEMS . '.' . Section\AgreementItem::FIELD_REQUIRED,
				) ? $item->required : null,
			);
		}

		return array_values($itemsByAgreementId);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[] $selectedFields
	 */
	private static function createLegacyAgreementItem(
		array $row,
		int $agreementId,
		array $selectedFields,
	): Section\AgreementItem
	{
		$itemsPath = Section\Agreements::FIELD_ITEMS . '.';

		return new Section\AgreementItem(
			agreementId: self::isLeafSelected(
				$selectedFields,
				$itemsPath . Section\AgreementItem::FIELD_AGREEMENT_ID,
			) ? $agreementId : null,
			checked: self::boolean(
				$row,
				'LICENCE_BUTTON_IS_CHECKED',
				self::isLeafSelected($selectedFields, $itemsPath . Section\AgreementItem::FIELD_CHECKED),
			),
			required: self::isLeafSelected(
				$selectedFields,
				$itemsPath . Section\AgreementItem::FIELD_REQUIRED,
			) ? true : null,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createSubmission(array $row, WebFormSelect $select): ?Section\Submission
	{
		if (!$select->hasSection(WebForm::SECTION_SUBMISSION))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_SUBMISSION);

		return new Section\Submission(
			success: self::createSubmissionResult(
				$row,
				$fields,
				Section\Submission::FIELD_SUCCESS,
				'RESULT_SUCCESS_URL',
				'RESULT_SUCCESS_TEXT',
			),
			failure: self::createSubmissionResult(
				$row,
				$fields,
				Section\Submission::FIELD_FAILURE,
				'RESULT_FAILURE_URL',
				'RESULT_FAILURE_TEXT',
			),
			redirectDelay: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_SUBMISSION,
				Section\Submission::FIELD_REDIRECT_DELAY,
			),
			refill: self::createSubmissionRefill($row, $fields),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[] $selectedFields
	 */
	private static function createSubmissionRefill(array $row, array $selectedFields): ?Section\SubmissionRefill
	{
		$path = Section\Submission::FIELD_REFILL;
		if (!self::hasSelectedPath($selectedFields, $path))
		{
			return null;
		}

		return new Section\SubmissionRefill(
			enabled: self::settingsValue(
				$row,
				$selectedFields,
				WebForm::SECTION_SUBMISSION,
				$path . '.' . Section\SubmissionRefill::FIELD_ENABLED,
			),
			caption: self::settingsValue(
				$row,
				$selectedFields,
				WebForm::SECTION_SUBMISSION,
				$path . '.' . Section\SubmissionRefill::FIELD_CAPTION,
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[] $selectedFields
	 */
	private static function createSubmissionResult(
		array $row,
		array $selectedFields,
		string $path,
		string $urlColumn,
		string $textColumn,
	): ?Section\SubmissionResult
	{
		if (!self::hasSelectedPath($selectedFields, $path))
		{
			return null;
		}

		return new Section\SubmissionResult(
			url: self::nullableString(
				$row,
				$urlColumn,
				self::isLeafSelected($selectedFields, $path . '.' . Section\SubmissionResult::FIELD_URL),
			),
			text: self::nullableString(
				$row,
				$textColumn,
				self::isLeafSelected($selectedFields, $path . '.' . Section\SubmissionResult::FIELD_TEXT),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createPayment(array $row, WebFormSelect $select): ?Section\Payment
	{
		if (!$select->hasSection(WebForm::SECTION_PAYMENT))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_PAYMENT);

		return new Section\Payment(
			enabled: self::boolean(
				$row,
				'IS_PAY',
				self::isLeafSelected($fields, Section\Payment::FIELD_ENABLED),
			),
			disabledPaySystemsId: self::settingsValue(
				$row,
				$fields,
				WebForm::SECTION_PAYMENT,
				Section\Payment::FIELD_DISABLED_PAY_SYSTEMS_ID,
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createCallback(array $row, WebFormSelect $select): ?Section\Callback
	{
		if (!$select->hasSection(WebForm::SECTION_CALLBACK))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_CALLBACK);

		return new Section\Callback(
			enabled: self::boolean(
				$row,
				'IS_CALLBACK_FORM',
				self::isLeafSelected($fields, Section\Callback::FIELD_ENABLED),
			),
			from: self::nullableString(
				$row,
				'CALL_FROM',
				self::isLeafSelected($fields, Section\Callback::FIELD_FROM),
			),
			text: self::nullableString(
				$row,
				'CALL_TEXT',
				self::isLeafSelected($fields, Section\Callback::FIELD_TEXT),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createBooking(array $row, WebFormSelect $select): ?Section\Booking
	{
		if (!$select->hasSection(WebForm::SECTION_BOOKING))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_BOOKING);

		return new Section\Booking(
			enabled: self::boolean(
				$row,
				'IS_BOOKING_FORM',
				self::isLeafSelected($fields, Section\Booking::FIELD_ENABLED),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createAnalytics(array $row, WebFormSelect $select): ?Section\Analytics
	{
		if (!$select->hasSection(WebForm::SECTION_ANALYTICS))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_ANALYTICS);

		return new Section\Analytics(
			googleAnalyticsId: self::nullableString(
				$row,
				'GOOGLE_ANALYTICS_ID',
				self::isLeafSelected($fields, Section\Analytics::FIELD_GOOGLE_ANALYTICS_ID),
			),
			googleAnalyticsPageViewEnabled: self::boolean(
				$row,
				'GOOGLE_ANALYTICS_PAGE_VIEW',
				self::isLeafSelected($fields, Section\Analytics::FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED),
			),
			yandexMetricaId: self::nullableString(
				$row,
				'YANDEX_METRIC_ID',
				self::isLeafSelected($fields, Section\Analytics::FIELD_YANDEX_METRICA_ID),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createSpamProtection(array $row, WebFormSelect $select): ?Section\SpamProtection
	{
		if (!$select->hasSection(WebForm::SECTION_SPAM_PROTECTION))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_SPAM_PROTECTION);

		return new Section\SpamProtection(
			enabled: self::boolean(
				$row,
				'USE_CAPTCHA',
				self::isLeafSelected($fields, Section\SpamProtection::FIELD_ENABLED),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, array<int, mixed>> $loadedSections
	 */
	private static function createIntegrations(
		array $row,
		array $loadedSections,
		int $formId,
		WebFormSelect $select,
	): ?Section\Integrations
	{
		if (!$select->hasSection(WebForm::SECTION_INTEGRATIONS))
		{
			return null;
		}

		$fields = $select->getSectionFields(WebForm::SECTION_INTEGRATIONS);
		$loaded = $loadedSections[WebForm::SECTION_INTEGRATIONS][$formId] ?? null;
		$loaded = $loaded instanceof Section\Integrations ? $loaded : null;

		return new Section\Integrations(
			whatsAppEnabled: self::boolean(
				$row,
				'IS_WHATSAPP_FORM',
				self::isLeafSelected($fields, Section\Integrations::FIELD_WHATS_APP_ENABLED),
			),
			ads: self::hasSelectedPath($fields, Section\Integrations::FIELD_ADS)
				? ($loaded?->ads ?? [])
				: null,
		);
	}

	/**
	 * @param string[] $selectedFields
	 */
	private static function hasSelectedPath(array $selectedFields, string $path): bool
	{
		foreach ($selectedFields as $selectedField)
		{
			if ($selectedField === $path || str_starts_with($selectedField, $path . '.'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $selectedFields
	 */
	private static function isLeafSelected(array $selectedFields, string $path): bool
	{
		foreach ($selectedFields as $selectedField)
		{
			if ($selectedField === $path || str_starts_with($path, $selectedField . '.'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function nullableString(array $row, string $column, bool $selected): ?string
	{
		if (!$selected)
		{
			return null;
		}

		$value = $row[$column] ?? null;

		return $value === null ? null : (string)$value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function string(array $row, string $column, bool $selected): ?string
	{
		if (!$selected)
		{
			return null;
		}

		return (string)($row[$column] ?? '');
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function nullableInteger(array $row, string $column, bool $selected): ?int
	{
		if (!$selected)
		{
			return null;
		}

		$value = $row[$column] ?? null;

		return $value === null ? null : (int)$value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function integer(array $row, string $column, bool $selected): ?int
	{
		if (!$selected)
		{
			return null;
		}

		return (int)($row[$column] ?? 0);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function boolean(array $row, string $column, bool $selected): ?bool
	{
		if (!$selected)
		{
			return null;
		}

		$value = $row[$column] ?? null;

		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}

	/**
	 * The version of the form, with the creation time standing in for an empty column.
	 *
	 * The column stays nullable for good on a portal the release updater has added it to: adding a
	 * `NOT NULL` column without a default to a live table is forbidden, and the updater does not
	 * tighten it afterwards either. Nor is it always filled: an installation that takes the schema
	 * changes of an update while deferring the state ones
	 * ({@see \Bitrix\Main\UpdateSystem\Migration\DatabaseUpdateMode::DdlOnly}) gets the column and
	 * not the backfill that follows it, and keeps it empty until a full update. So the stand-in is
	 * part of the read contract rather than a measure for the window before the backfill: it is the
	 * same value the backfill stores, so a client that reads it and sends it back as the expected
	 * version passes the check of the write.
	 *
	 * @param array<string, mixed> $row
	 * @throws SystemException
	 */
	private static function modifiedTime(array $row, bool $selected): ?DateTime
	{
		return self::dateTime($row, 'DATE_MODIFY', $selected)
			?? self::dateTime($row, 'DATE_CREATE', $selected)
		;
	}

	/**
	 * @param array<string, mixed> $row
	 * @throws SystemException
	 */
	private static function dateTime(array $row, string $column, bool $selected): ?DateTime
	{
		if (!$selected)
		{
			return null;
		}

		$value = $row[$column] ?? null;
		if ($value !== null && !$value instanceof DateTime)
		{
			throw new SystemException(
				sprintf('Unexpected value of web form column `%s`: %s', $column, get_debug_type($value)),
			);
		}

		return $value;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormSettingsOverflowException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormVersionMismatchException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\AgreementSectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\DependencySectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\FieldSectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\IntegrationSectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\PresetFieldSectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\ResponsibleSectionWriter;
use Bitrix\Crm\V2\Internal\Repository\WebForm\Section\WebFormSectionWriter;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Stores the changes of a default CRM form.
 *
 * The form row is written with the touched columns only, and only the touched sections are
 * rewritten: what the change set leaves alone is not part of the write at all. The settings column
 * is the one column carrying more than a single setting, so a write of it merges the sent sub keys
 * into the stored tree ({@see FormSettingsColumn}) and everything else stored there survives. The
 * settings of the embedding variants live in that column as well and are written by
 * {@see updateEmbedding()}: their own contract, the same column, the same merge.
 *
 * There is no transaction: the first unsuccessful write stops the cycle and its errors are
 * returned, so an unsuccessful result means the operation did not finish, not that nothing
 * changed. The id of the form is in the result data of both operations. The switch of the
 * activity is the one exception ({@see setActive()}): it is a single row, and its refusal has to
 * leave the version the caller holds acting.
 *
 * A form removed in between is answered as a missing form ({@see FormNotFoundException}) by every
 * operation that reserves or compares the version, the way the scenarios answer a form they did
 * not find.
 *
 * A merge outgrowing the settings column is refused by every operation writing it
 * ({@see FormSettingsOverflowException}): the column holds one tree, and storing a cut one would
 * lose the settings of the form altogether.
 *
 * @internal
 */
final class FormWriteRepository
{
	public const DATA_FORM_ID = 'id';

	/** The version the form carries after the write, read back from the row. */
	public const DATA_MODIFIED_TIME = 'modifiedTime';

	private const TYPE_BOOLEAN = 'boolean';
	private const TYPE_STRING = 'string';

	/**
	 * Writable paths of the form row. Extends the read maps of FormRepository with the direction
	 * of writing: paths of collections and of the responsible queue belong to section writers, the
	 * booking column has no path at all because the server derives it, and the background image
	 * column has none because only uploading a file fills it.
	 *
	 * The activity is here for the one write that carries it - the creation, which stores the form
	 * in the state it is meant to work in by the very row that makes it. A change never reaches this
	 * path: the write contract leaves it out of that context ({@see WebFormChangeSet}), and a stored
	 * form is switched by {@see setActive()}.
	 */
	private const COLUMN_BY_PATH = [
		WebForm::FIELD_NAME => 'NAME',
		WebForm::FIELD_ACTIVE => 'ACTIVE',
		WebForm::FIELD_LANGUAGE_ID => 'LANGUAGE_ID',
		WebForm::FIELD_TEMPLATE_ID => 'TEMPLATE_ID',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_TITLE => 'CAPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_DESCRIPTION => 'DESCRIPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_CAPTION => 'BUTTON_CAPTION',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_TEXT_COLOR => 'BUTTON_COLOR_FONT',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR => 'BUTTON_COLOR_BG',
		WebForm::SECTION_APPEARANCE . '.' . Section\Appearance::FIELD_COPYRIGHT_REMOVED => 'COPYRIGHT_REMOVED',
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME => 'ENTITY_SCHEME',
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_DUPLICATE_MODE => 'DUPLICATE_MODE',
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
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_GOOGLE_ANALYTICS_ID => 'GOOGLE_ANALYTICS_ID',
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED
			=> 'GOOGLE_ANALYTICS_PAGE_VIEW',
		WebForm::SECTION_ANALYTICS . '.' . Section\Analytics::FIELD_YANDEX_METRICA_ID => 'YANDEX_METRIC_ID',
		WebForm::SECTION_SPAM_PROTECTION . '.' . Section\SpamProtection::FIELD_ENABLED => 'USE_CAPTCHA',
		WebForm::SECTION_INTEGRATIONS . '.' . Section\Integrations::FIELD_WHATS_APP_ENABLED => 'IS_WHATSAPP_FORM',
	];

	/**
	 * Columns the read projection types differently from the storage are written the way the column
	 * accepts: the entity scheme is read as a number while the column is an enum over string codes.
	 */
	private const COLUMN_TYPE = [
		'ACTIVE' => self::TYPE_BOOLEAN,
		'COPYRIGHT_REMOVED' => self::TYPE_BOOLEAN,
		'USE_LICENCE' => self::TYPE_BOOLEAN,
		'IS_PAY' => self::TYPE_BOOLEAN,
		'IS_CALLBACK_FORM' => self::TYPE_BOOLEAN,
		'GOOGLE_ANALYTICS_PAGE_VIEW' => self::TYPE_BOOLEAN,
		'USE_CAPTCHA' => self::TYPE_BOOLEAN,
		'IS_WHATSAPP_FORM' => self::TYPE_BOOLEAN,
	];

	/**
	 * Legacy columns holding a single value of a collection that now lives in its own table. Writing
	 * the collection clears them, the way every legacy save did; otherwise the read projection would
	 * keep merging a value the collection no longer contains.
	 */
	private const CLEARED_COLUMN_BY_PATH = [
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_USER_IDS => 'ASSIGNED_BY_ID',
		WebForm::SECTION_AGREEMENTS . '.' . Section\Agreements::FIELD_ITEMS => 'AGREEMENT_ID',
	];

	/**
	 * Creation defaults of a form row, taken from the legacy write path (`WebForm\Form`). The
	 * settings column is absent here: its own seed belongs to the collaboration owning that column
	 * ({@see FormSettingsColumn::creationDefaults()}), so the seeded values and the values the read
	 * answers cannot drift apart.
	 *
	 * A form is created working, which is what a form is made for; a creation saying otherwise
	 * carries the activity itself and takes this default over. Every default here works that way -
	 * they fill in only the columns the change set says nothing about.
	 */
	private const CREATION_DEFAULTS = [
		'TYPE_ID' => FormTable::TYPE_DEFAULT,
		'ACTIVE' => 'Y',
		'IS_SYSTEM' => 'N',
		'COPYRIGHT_REMOVED' => 'N',
		'IS_PAY' => 'N',
		'LANGUAGE_ID' => '',
		'USE_CAPTCHA' => 'N',
		'USE_LICENCE' => 'N',
		'LICENCE_BUTTON_IS_CHECKED' => 'Y',
		'INVOICE_SETTINGS' => [],
	];

	/**
	 * Sections are written in the order of the legacy save: the dependency rules come after the
	 * fields because they are filtered by the field composition already stored.
	 */
	private const SECTION_WRITE_ORDER = [
		WebForm::SECTION_RESPONSIBLE,
		WebForm::SECTION_PRESET_FIELDS,
		WebForm::SECTION_INTEGRATIONS,
		WebForm::SECTION_FIELDS,
		WebForm::SECTION_DEPENDENCIES,
		WebForm::SECTION_AGREEMENTS,
	];

	/** @var array<string, WebFormSectionWriter> */
	private array $sectionWritersByName = [];

	/**
	 * Creates a repository with default or injected section writers.
	 *
	 * @param null|array<WebFormSectionWriter> $sectionWriters
	 */
	public function __construct(?array $sectionWriters = null)
	{
		$sectionWriters ??= self::createDefaultSectionWriters();
		foreach ($sectionWriters as $writer)
		{
			$sectionName = $writer->getSectionName();
			if (isset($this->sectionWritersByName[$sectionName]))
			{
				throw new InvalidArgumentException(sprintf('Duplicate web form section writer `%s`', $sectionName));
			}

			$this->sectionWritersByName[$sectionName] = $writer;
		}
	}

	/**
	 * Creates a default CRM form out of the change set. The id of the created form is in the result
	 * data even when a section written after the row fails.
	 */
	public function add(WebFormChangeSet $changeSet): Result
	{
		$result = new Result();
		$columns = self::getTouchedColumns($changeSet, 0);
		foreach (self::CREATION_DEFAULTS as $column => $value)
		{
			if (!array_key_exists($column, $columns))
			{
				$columns[$column] = $value;
			}
		}

		$columns[FormSettingsColumn::COLUMN] = self::mergeSettings(
			FormSettingsColumn::creationDefaults(),
			FormSettingsColumn::writes($changeSet),
		);
		$columns['DATE_CREATE'] = new DateTime();

		$addResult = FormTable::add($columns);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		$formId = (int)$addResult->getId();
		$result->setData([self::DATA_FORM_ID => $formId]);

		return $this->writeSections($formId, $changeSet, $result);
	}

	/**
	 * Applies the change set to an existing CRM form, leaving every untouched column and section
	 * as it is.
	 */
	public function update(
		int $formId,
		WebFormChangeSet $changeSet,
		?DateTime $expectedModifiedTime = null,
	): Result
	{
		$result = new Result();
		if ($formId <= 0)
		{
			return $result->addError(new Error(sprintf('Invalid web form id `%d`', $formId), WebForm::FIELD_ID));
		}

		$result->setData([self::DATA_FORM_ID => $formId]);
		if ($changeSet->isEmpty())
		{
			if ($expectedModifiedTime !== null)
			{
				$this->assertExpectedModifiedTime($formId, $expectedModifiedTime);
			}

			return $result;
		}

		$columns = self::getTouchedColumns($changeSet, $formId);
		$settingsWrites = FormSettingsColumn::writes($changeSet);
		$this->assertTouchedSectionWritersAreAvailable($changeSet);

		self::reserveNextModifiedTime($formId, $expectedModifiedTime);

		if ($settingsWrites !== [])
		{
			// The column is read after the version is reserved and written by the update of the row
			// below, so the sub keys of the write travel in the same statement as every other
			// column. What the legacy editor stores in between is lost - the window is narrowed by
			// this order and not closed by it, because the write holds no transaction.
			$columns[FormSettingsColumn::COLUMN] = self::mergeSettings(
				self::readSettingsColumn($formId),
				$settingsWrites,
			);
		}

		if ($columns !== [])
		{
			$columns['DATE_MODIFY'] = new SqlExpression('?#', 'DATE_MODIFY');
			$updateResult = FormTable::update($formId, $columns);
			if (!$updateResult->isSuccess())
			{
				return $result->addErrors($updateResult->getErrors());
			}
		}

		return $this->writeSections($formId, $changeSet, $result);
	}

	/**
	 * Turns an existing CRM form on or off, answering with the version it carries after the write.
	 *
	 * The version moves exactly once. It is reserved the way every write of this class reserves it,
	 * and the update of the row then assigns the column to itself, because the update event of the
	 * table fills the version in whenever the column is absent from the write
	 * ({@see FormTable::onBeforeUpdate()}): leaving it out would move the version a second time and
	 * the caller would be answered a value the row no longer carries. The answer is read back from
	 * the row for the same reason - the reserved value is decided by the database and not here.
	 *
	 * The author of the change travels only when the form is turned on. Turning it off, the event
	 * of the table takes the column out of the write, so this operation promises no author there.
	 *
	 * The expected version is optional, the way it is in {@see update()}: a caller switching the form
	 * it has just read guards the switch with the version it read, and a caller naming the form alone
	 * switches it as it always did. The comparison travels in the reservation of the next version, so
	 * the version is compared and moved by one statement and no window is left between them.
	 *
	 * The reservation and the write of the row are one transaction. A write refused after the
	 * version was reserved rolls the reservation back with it, so the refusal leaves the form with
	 * the version the caller holds, and repeating the switch with that version is not answered with
	 * a conflict that nothing written caused.
	 *
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormNotFoundException the form was removed after the caller read it
	 */
	public function setActive(
		int $formId,
		bool $isActive,
		int $userId,
		?DateTime $expectedModifiedTime = null,
	): Result
	{
		$result = new Result();
		if ($formId <= 0)
		{
			return $result->addError(new Error(sprintf('Invalid web form id `%d`', $formId), WebForm::FIELD_ID));
		}

		$columns = [
			'ACTIVE' => self::toFlag($isActive),
			'DATE_MODIFY' => new SqlExpression('?#', 'DATE_MODIFY'),
		];
		if ($isActive)
		{
			$columns['ACTIVE_CHANGE_BY'] = $userId;
		}

		$connection = FormTable::getEntity()->getConnection();
		$connection->startTransaction();
		try
		{
			self::reserveNextModifiedTime($formId, $expectedModifiedTime);
			$updateResult = FormTable::update($formId, $columns);
			$modifiedTime = $updateResult->isSuccess() ? self::getCurrentModifiedTime($formId) : null;
		}
		catch (Throwable $failure)
		{
			self::rollBack($connection);

			throw $failure;
		}

		if (!$updateResult->isSuccess())
		{
			self::rollBack($connection);

			return $result->addErrors($updateResult->getErrors());
		}

		$connection->commitTransaction();

		return $result->setData([
			self::DATA_FORM_ID => $formId,
			self::DATA_MODIFIED_TIME => $modifiedTime,
		]);
	}

	/**
	 * Takes back what the transaction of {@see setActive()} wrote. A nested rollback undoes its
	 * savepoint and then reports itself as unsupported
	 * ({@see \Bitrix\Main\DB\MysqlCommonConnection::rollbackTransaction()}); by then the write is
	 * undone, so the complaint must not replace the refusal the caller is answered with.
	 */
	private static function rollBack(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}

		FormTable::cleanCache();
	}

	/**
	 * Applies the change set of the embedding to the settings column of an existing CRM form.
	 *
	 * The settings of the variants live in one sub key of that column, so the write follows the same
	 * order as every other write of it: the version is reserved, the column is read, the sent nodes
	 * are merged into the stored tree and the result travels in one update of the row. The settings
	 * of the variants the change set says nothing about, the neighbouring sub keys and the keys this
	 * contract knows nothing about are stored back as they were read - and a block of the variants
	 * the column holds empty is stored back as the read answers it
	 * ({@see FormSettingsColumn::withDefaultViews()}), so that writing one setting takes none of the
	 * others away.
	 *
	 * The version is not optional here: the embedding is written by a method of its own, and a form
	 * has one rule of concurrent change rather than two.
	 *
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 * @throws FormNotFoundException the form was removed after the caller read it
	 * @throws FormSettingsOverflowException the merged settings no longer fit the column
	 */
	public function updateEmbedding(
		int $formId,
		EmbeddingChangeSet $changeSet,
		DateTime $expectedModifiedTime,
	): Result
	{
		$result = new Result();
		if ($formId <= 0)
		{
			return $result->addError(new Error(sprintf('Invalid web form id `%d`', $formId), WebForm::FIELD_ID));
		}

		$result->setData([self::DATA_FORM_ID => $formId]);
		if ($changeSet->isEmpty())
		{
			$this->assertExpectedModifiedTime($formId, $expectedModifiedTime);

			return $result;
		}

		self::reserveNextModifiedTime($formId, $expectedModifiedTime);

		$updateResult = FormTable::update($formId, [
			FormSettingsColumn::COLUMN => self::mergeSettings(
				FormSettingsColumn::withDefaultViews(self::readSettingsColumn($formId)),
				self::viewWrites($changeSet),
			),
			'DATE_MODIFY' => new SqlExpression('?#', 'DATE_MODIFY'),
		]);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		return $result;
	}

	/**
	 * The stored column with the sent sub keys merged into it, refused when the result no longer fits
	 * the column. Cutting it instead would leave the column unreadable as a whole, and the settings
	 * this contract knows nothing about would go down with the one that overflowed it.
	 *
	 * The refusal belongs to no field of the input: the size is made up by the whole tree, so it is
	 * raised as a refusal of the write rather than added to the result as an error of a value.
	 *
	 * @param array<string, mixed> $writes
	 * @return array<string, mixed>
	 * @throws FormSettingsOverflowException
	 */
	private static function mergeSettings(mixed $stored, array $writes): array
	{
		$merged = FormSettingsColumn::merge($stored, $writes);
		if (FormSettingsColumn::storedSize($merged) > FormSettingsColumn::MAX_STORED_BYTES)
		{
			throw new FormSettingsOverflowException();
		}

		return $merged;
	}

	/**
	 * Sub keys of the settings column the change set of the embedding writes, keyed by the path of
	 * the sub key. What the storage holds for a setting is the change set talking; where inside the
	 * column the block of the variants stands is the column talking.
	 *
	 * @return array<string, mixed>
	 */
	private static function viewWrites(EmbeddingChangeSet $changeSet): array
	{
		$writes = [];
		foreach ($changeSet->storedWrites() as $storedPath => $value)
		{
			$writes[FormSettingsColumn::viewsPath((string)$storedPath)] = $value;
		}

		return $writes;
	}

	private function assertTouchedSectionWritersAreAvailable(WebFormChangeSet $changeSet): void
	{
		foreach (self::SECTION_WRITE_ORDER as $sectionName)
		{
			if (
				$changeSet->touches($sectionName)
				&& !isset($this->sectionWritersByName[$sectionName])
			)
			{
				throw new InvalidArgumentException(sprintf('Missing web form section writer `%s`', $sectionName));
			}
		}
	}

	private static function reserveNextModifiedTime(int $formId, ?DateTime $expectedModifiedTime): void
	{
		$filter = Query::filter()->where('ID', $formId);
		if ($expectedModifiedTime !== null)
		{
			$filter->where(self::createExpectedModifiedTimeFilter($expectedModifiedTime));
		}

		if (self::moveModifiedTime($filter) === 1)
		{
			return;
		}

		// Nothing moved: either the form is gone, which the read below answers for, or it no longer
		// carries the expected version.
		$currentModifiedTime = self::getCurrentModifiedTime($formId);
		if ($expectedModifiedTime !== null)
		{
			throw new FormVersionMismatchException($currentModifiedTime);
		}
	}

	/**
	 * Claims an existing CRM form for a removal that can not be taken back, and answers whether the
	 * row was claimed.
	 *
	 * This is what makes the guards of the removal hold. Compared on their own they answer about a
	 * row a parallel request may change a moment later, and the cascade would run on the changed one
	 * all the same; here the comparison and the write are one statement, so a form changed or turned
	 * on since is not claimed at all and nothing destroying runs on it. The claim moves the version
	 * the way every write of this class moves it ({@see reserveNextModifiedTime()}), which is what
	 * takes the row away from any other write comparing versions.
	 *
	 * A caller expecting no version of a form it may remove working guards nothing, so there is
	 * nothing to claim the row against and the row is left alone.
	 *
	 * @param DateTime|null $expectedModifiedTime the version the row has to still carry; null claims
	 *        whatever version it carries
	 * @param bool $requireInactive the row has to still be a form that does not work
	 * @return bool false says the form is gone or no longer answers the conditions; which of the two
	 *         it is belongs to the caller owning the refusals
	 */
	public function claimForRemoval(int $formId, ?DateTime $expectedModifiedTime, bool $requireInactive): bool
	{
		if ($expectedModifiedTime === null && !$requireInactive)
		{
			return true;
		}

		$filter = Query::filter()->where('ID', $formId);
		if ($expectedModifiedTime !== null)
		{
			$filter->where(self::createExpectedModifiedTimeFilter($expectedModifiedTime));
		}

		if ($requireInactive)
		{
			// A working form is the one carrying `Y`, the way the read projects the column
			// ({@see FormRepository}), so anything else is a form that does not work.
			$filter->whereNot('ACTIVE', 'Y');
		}

		return self::moveModifiedTime($filter) === 1;
	}

	/**
	 * Moves the version of every row the filter matches and answers how many rows that was. The next
	 * version is the current time, or one second past the stored one while that one is still ahead,
	 * so the column keeps growing on a row written twice within the same second.
	 */
	private static function moveModifiedTime(ConditionTree $filter): int
	{
		$entity = FormTable::getEntity();
		$connection = $entity->getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = FormTable::getTableName();
		$modifiedTimeColumn = $sqlHelper->quote('DATE_MODIFY');
		$currentTime = $sqlHelper->convertToDbDateTime(new DateTime());
		$incrementedModifiedTime = $sqlHelper->addSecondsToDateTime(1, $modifiedTimeColumn);
		$nextModifiedTime = new SqlExpression(
			"CASE WHEN {$modifiedTimeColumn} IS NULL OR {$modifiedTimeColumn} < {$currentTime}"
			. " THEN {$currentTime} ELSE {$incrementedModifiedTime} END",
		);

		[$update, $binds] = $sqlHelper->prepareUpdate($tableName, ['DATE_MODIFY' => $nextModifiedTime]);
		$where = Query::buildFilterSql($entity, $filter);
		$sql =
			'UPDATE ' . $sqlHelper->quote($tableName)
			. ' SET ' . $update
			. ' WHERE ' . $where
		;

		$connection->queryExecute($sql, $binds);
		$affectedRowsCount = $connection->getAffectedRowsCount();
		if ($affectedRowsCount > 0)
		{
			FormTable::cleanCache();
		}

		return $affectedRowsCount;
	}

	/**
	 * The version the write accepts as the expected one. The column stays nullable for good on a
	 * portal the release updater has added it to, and an installation deferring the state changes
	 * of an update keeps it empty for as long as it defers them
	 * ({@see FormRepository::modifiedTime()}), so a form with an empty one is not a passing state:
	 * whenever the column is empty the read answers with the creation time, and the write has to
	 * take that value back. Comparing an empty column matches nothing, and every update of such a
	 * form would answer with a conflict the client can not get out of by rereading.
	 */
	private static function createExpectedModifiedTimeFilter(DateTime $expectedModifiedTime): ConditionTree
	{
		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->where('DATE_MODIFY', $expectedModifiedTime)
			->where(
				Query::filter()
					->whereNull('DATE_MODIFY')
					->where('DATE_CREATE', $expectedModifiedTime),
			)
		;
	}

	/**
	 * Refuses when the form no longer carries the version the caller expects, and moves no version
	 * of its own.
	 *
	 * Serves the write that writes nothing: an empty change set compares the version and stops
	 * there, and reserving the next one would move the version of a form nothing was written to.
	 * Every operation that does touch the row compares the version by reserving the next one
	 * instead - the write ({@see reserveNextModifiedTime()}) and the claim of a removal
	 * ({@see claimForRemoval()}): both are about to move the version anyway, and one statement
	 * leaves no window between the comparison and what follows it.
	 *
	 * @throws FormVersionMismatchException the form was changed after the expected version was read
	 */
	private function assertExpectedModifiedTime(int $formId, DateTime $expectedModifiedTime): void
	{
		$currentModifiedTime = self::getCurrentModifiedTime($formId);
		if ($currentModifiedTime->getTimestamp() !== $expectedModifiedTime->getTimestamp())
		{
			throw new FormVersionMismatchException($currentModifiedTime);
		}
	}

	/**
	 * The version of the stored form, with the creation time standing in for an empty column. The
	 * stand-in answers the same way the read of the form does, so a conflict stays a conflict the
	 * client can resolve instead of an unexplained internal failure.
	 *
	 * @throws FormNotFoundException the form was removed in between
	 */
	private static function getCurrentModifiedTime(int $formId): DateTime
	{
		$row = FormTable::query()
			->setSelect(['DATE_MODIFY', 'DATE_CREATE'])
			->where('ID', $formId)
			->fetch()
		;
		if (!is_array($row))
		{
			throw new FormNotFoundException();
		}

		$modifiedTime = $row['DATE_MODIFY'] ?? $row['DATE_CREATE'] ?? null;
		if (!$modifiedTime instanceof DateTime)
		{
			throw new UnexpectedValueException(sprintf('Web form `%d` has no modified time.', $formId));
		}

		return $modifiedTime;
	}

	private static function readSettingsColumn(int $formId): mixed
	{
		$row = FormTable::query()
			->setSelect([FormSettingsColumn::COLUMN])
			->where('ID', $formId)
			->fetch()
		;

		return is_array($row) ? ($row[FormSettingsColumn::COLUMN] ?? null) : null;
	}

	private function writeSections(int $formId, WebFormChangeSet $changeSet, Result $result): Result
	{
		foreach (self::SECTION_WRITE_ORDER as $sectionName)
		{
			if (!$changeSet->touches($sectionName))
			{
				continue;
			}

			$writer = $this->sectionWritersByName[$sectionName] ?? null;
			if ($writer === null)
			{
				throw new InvalidArgumentException(sprintf('Missing web form section writer `%s`', $sectionName));
			}

			$writeResult = $writer->write($formId, $changeSet);
			if (!$writeResult->isSuccess())
			{
				return $result->addErrors($writeResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @return WebFormSectionWriter[]
	 */
	private static function createDefaultSectionWriters(): array
	{
		return [
			new ResponsibleSectionWriter(),
			new PresetFieldSectionWriter(),
			new IntegrationSectionWriter(),
			new FieldSectionWriter(),
			new DependencySectionWriter(),
			new AgreementSectionWriter(),
		];
	}

	/**
	 * Columns of the form row the change set touches. Nothing else takes part in the write.
	 *
	 * @param int $formId the form being changed; zero while it is being created and nothing is stored
	 * @return array<string, mixed>
	 */
	private static function getTouchedColumns(WebFormChangeSet $changeSet, int $formId): array
	{
		$columns = [];
		foreach ($changeSet->paths() as $path)
		{
			$column = self::COLUMN_BY_PATH[$path] ?? null;
			if ($column !== null)
			{
				$columns[$column] = self::convert($column, $changeSet->get($path));

				continue;
			}

			$clearedColumn = self::CLEARED_COLUMN_BY_PATH[$path] ?? null;
			if ($clearedColumn !== null)
			{
				$columns[$clearedColumn] = null;

				continue;
			}

			// A path of the settings column takes no part in this map: its value is merged into the
			// stored column instead of replacing a column of the row.
			if (FormSettingsColumn::owns($path))
			{
				continue;
			}

			if (!in_array(explode('.', $path, 2)[0], self::SECTION_WRITE_ORDER, true))
			{
				throw new InvalidArgumentException(sprintf('Unmapped web form change path `%s`', $path));
			}
		}

		if ($changeSet->has(WebForm::SECTION_FIELDS))
		{
			// The field composition is the only source of the booking flag, so a change set without
			// the fields leaves the column as it is. The composition asked about is the one the form
			// ends up with, which is why the writer owning the rows answers: a field sent without a
			// type keeps the type of its stored row, and reading the request alone would switch a
			// booking form out of booking on a change touching something else.
			$columns['IS_BOOKING_FORM'] = self::toFlag(
				FieldSectionWriter::composesBookingForm($formId, $changeSet->get(WebForm::SECTION_FIELDS)),
			);
		}

		return $columns;
	}

	private static function convert(string $column, mixed $value): mixed
	{
		return match (self::COLUMN_TYPE[$column] ?? self::TYPE_STRING)
		{
			// A flag column has no absent state, so clearing it stores the negative value.
			self::TYPE_BOOLEAN => self::toFlag($value),
			default => $value === null ? null : (string)$value,
		};
	}

	private static function toFlag(mixed $value): string
	{
		return $value === true || $value === 1 || $value === '1' || $value === 'Y' ? 'Y' : 'N';
	}
}

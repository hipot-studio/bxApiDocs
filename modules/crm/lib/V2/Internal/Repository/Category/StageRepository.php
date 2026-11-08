<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category;

use Bitrix\Crm\Entry\EntryException;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * @internal
 */
class StageRepository implements StageRepositoryInterface
{
	/**
	 * The domain field name to the field of `b_crm_status` behind it. `SORT` is missing on purpose:
	 * every write decides it, with or without the caller.
	 */
	private const BOUNDARY_FIELD_BY_DOMAIN_FIELD = [
		'name' => 'NAME',
		'color' => 'COLOR',
		'semantics' => 'SEMANTICS',
	];

	/**
	 * The record id of every stage this repository has already read or written, keyed by the category
	 * and the identifier the domain addresses the stage by.
	 *
	 * A write addresses a stage by that identifier, and the boundary keeps its stages per category, so
	 * resolving one costs a read of the whole set ({@see Factory::getStageFromCategory()}) - once per
	 * stage written, which is what makes a group replacement quadratic. The record id is all a write
	 * needs, and it does not go stale while the stage is there: `STATUS_ID` cannot be changed
	 * ({@see StatusTable::onBeforeUpdate()}), so nothing but a deletion can make an entry wrong, and a
	 * deletion takes its entry with it.
	 *
	 * @var array<string, int>
	 */
	private array $recordIdByStage = [];

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	public function getById(int $categoryId, string $stageId): ?StageData
	{
		$factory = $this->getFactory($categoryId);
		if ($factory === null)
		{
			return null;
		}

		$stage = $factory->getStageFromCategory($categoryId, $stageId);

		return $stage === null ? null : $this->toData($stage, $categoryId);
	}

	public function findById(string $stageId, array $categoryIds): ?StageData
	{
		// Only the named categories are looked into, one after another until the stage turns up. The
		// boundary keeps its stages per category and would resolve a stage by its identifier alone by
		// reading every category of the entity type, so asking it that way would carry categories the
		// caller never named across the boundary.
		foreach ($categoryIds as $categoryId)
		{
			$stage = $this->getById($categoryId, $stageId);
			if ($stage !== null)
			{
				return $stage;
			}
		}

		return null;
	}

	/**
	 * @return StageData[]
	 */
	public function getAllByCategory(int $categoryId): array
	{
		$factory = $this->getFactory($categoryId);
		if ($factory === null)
		{
			return [];
		}

		$stages = $factory->getStages($categoryId)->getAll();

		// The boundary orders by SORT alone, so stages with an equal SORT come in an unspecified
		// order, which makes paging over them unstable.
		usort(
			$stages,
			static fn (EO_Status $a, EO_Status $b): int => [$a->getSort(), $a->getId()] <=> [$b->getSort(), $b->getId()],
		);

		return array_map(
			fn (EO_Status $stage): StageData => $this->toData($stage, $categoryId),
			$stages,
		);
	}

	public function add(int $categoryId, array $fields): Result
	{
		$factory = $this->requireFactory($categoryId);
		$this->assertFieldsWritable(array_keys($fields));
		self::assertFieldValuesAllowed($fields);

		$boundaryFields = $this->toBoundaryFields($fields);
		$boundaryFields['CATEGORY_ID'] = $categoryId;
		$boundaryFields['SORT'] = $fields['sort'] ?? $this->sortForNewStage($categoryId);

		$boundary = new \CCrmStatus($factory->getStagesEntityId($categoryId));
		try
		{
			$recordId = $boundary->Add($boundaryFields);
		}
		finally
		{
			$this->forgetMemoizedStages($factory);
		}

		if ($recordId === false)
		{
			return self::refusalOf($boundary);
		}

		return $this->written((int)$recordId, $categoryId);
	}

	public function update(int $categoryId, string $stageId, array $fields, bool $readBack = true): Result
	{
		$factory = $this->requireFactory($categoryId);
		$this->assertFieldsWritable(array_keys($fields));
		self::assertFieldValuesAllowed($fields);

		$stage = $this->requireStage($factory, $categoryId, $stageId);
		$recordId = (int)$stage->getId();

		$boundaryFields = $this->toBoundaryFields($fields);
		// The boundary writes SORT on every update and falls back to its own default when it gets
		// none, so a write that leaves the position alone has to carry the current one.
		$boundaryFields['SORT'] = $fields['sort'] ?? $stage->getSort();

		$clearsColor = ($boundaryFields['COLOR'] ?? null) === '';
		if ($clearsColor)
		{
			unset($boundaryFields['COLOR']);
		}

		$boundary = new \CCrmStatus($factory->getStagesEntityId($categoryId));
		try
		{
			$boundary->Update($recordId, $boundaryFields);
		}
		finally
		{
			$this->forgetMemoizedStages($factory);
		}

		if ((string)$boundary->GetLastError() !== '')
		{
			return self::refusalOf($boundary);
		}

		if ($clearsColor)
		{
			$cleared = $this->clearColor($factory, $recordId);
			if (!$cleared->isSuccess())
			{
				// The write before this one is stored, and undoing it has to drop the caches it filled -
				// this is the one refusal of a stage write that does
				// ({@see StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}).
				return $cleared->setData([self::DATA_KEY_WRITE_REACHED_STORAGE => true]);
			}
		}

		return $readBack ? $this->written($recordId, $categoryId) : new Result();
	}

	public function delete(int $categoryId, string $stageId): Result
	{
		$factory = $this->requireFactory($categoryId);
		$stage = $this->requireStage($factory, $categoryId, $stageId);

		try
		{
			// Not through `\CCrmStatus::Delete()`: it is deprecated in favour of this very call and
			// flattens the result of the boundary into a boolean on the way out.
			$result = StatusTable::delete($stage->getId());

			// A refused deletion erases no stage: the ORM collects the refusal of `onBeforeDelete` and
			// returns before the statement. What that event writes on its way - the field attributes of
			// the entity type - is another table, and the caches this repository drops are not its
			// ({@see StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}).
			return $result->isSuccess()
				? $result
				: $result->setData([self::DATA_KEY_WRITE_REACHED_STORAGE => false])
			;
		}
		finally
		{
			// Whether the deletion went through or not: a refused one costs the next write of this stage
			// a read of the whole set, and a stage that is gone must not be resolvable by its record id.
			unset($this->recordIdByStage[self::stageKey($categoryId, $stageId)]);
			$this->forgetMemoizedStages($factory);
		}
	}

	public function applySort(int $categoryId, array $stageIds): Result
	{
		$factory = $this->requireFactory($categoryId);
		$ordered = $this->orderedStages($factory, $categoryId, $stageIds);

		try
		{
			// From the tail of the set towards its head. A position is not weighed against the other
			// stages on an update the way it is on an add ({@see StatusTable::onBeforeUpdate()} against
			// {@see StatusTable::onBeforeAdd()}), and the direction no longer decides what the field
			// attributes end up as: {@see \Bitrix\Crm\Attribute\FieldAttributeManager::processPhaseSortModification()}
			// rebuilds them from the whole order of stages, the same result for any chain of
			// intermediate states it walks through. The direction is kept as it was.
			foreach (array_reverse($ordered, true) as $position => $stage)
			{
				$sort = ($position + 1) * self::SORT_STEP;
				if ((int)$stage->getSort() === $sort)
				{
					continue;
				}

				$result = StatusTable::update($stage->getId(), ['SORT' => $sort]);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}
		}
		finally
		{
			$this->forgetMemoizedStages($factory);
		}

		return new Result();
	}

	public function forgetCaches(int $categoryId): void
	{
		// The persistent one first: it is the only one that outlives the request, and it is what the
		// stage broker fills its memo from.
		StatusTable::cleanCache();

		$this->forgetMemos($categoryId);
	}

	public function forgetMemos(int $categoryId): void
	{
		$factory = $this->getFactory($categoryId);
		if ($factory !== null)
		{
			$this->forgetMemoizedStages($factory);
		}
	}

	/**
	 * Drops the colour of a stage past the boundary.
	 *
	 * `\CCrmStatus::Update()` prepends `#` to every colour that does not start with one and makes no
	 * exception for the empty colour, so a stage cleared through the boundary would keep `#` - neither
	 * a colour nor no colour, and refused by the contract a stage is read and written by. `Add()` is
	 * guarded against the same thing, which is why this is the only place that needs it.
	 */
	private function clearColor(Factory $factory, int $recordId): Result
	{
		try
		{
			return StatusTable::update($recordId, ['COLOR' => '']);
		}
		finally
		{
			$this->forgetMemoizedStages($factory);
		}
	}

	/**
	 * The stages of $categoryId in the order $stageIds names them, refusing anything but the whole
	 * set of them.
	 *
	 * @param string[] $stageIds
	 * @return EO_Status[]
	 * @throws ObjectNotFoundException
	 * @throws ArgumentException
	 */
	private function orderedStages(Factory $factory, int $categoryId, array $stageIds): array
	{
		$stored = [];
		foreach ($factory->getStages($categoryId)->getAll() as $stage)
		{
			$stored[$stage->getStatusId()] = $stage;
		}

		$ordered = [];
		foreach ($stageIds as $stageId)
		{
			$stageId = (string)$stageId;
			if (!isset($stored[$stageId]))
			{
				throw $this->stageNotFound($categoryId, $stageId);
			}

			if (isset($ordered[$stageId]))
			{
				throw new ArgumentException(
					"Stage {$stageId} of category {$categoryId} is named more than once.",
					'stageIds',
				);
			}

			$ordered[$stageId] = $stored[$stageId];
		}

		if (count($ordered) !== count($stored))
		{
			throw new ArgumentException(
				sprintf(
					'Category %d of entity type %d has %d stages, and the order names %d of them.',
					$categoryId,
					$this->entityTypeId,
					count($stored),
					count($ordered),
				),
				'stageIds',
			);
		}

		return array_values($ordered);
	}

	/**
	 * A field outside the write contract is refused before the boundary is touched at all. Unlike a
	 * category field, a stage field does not depend on the entity type: an entity type either has
	 * stages, and then all four fields are writable, or it has none.
	 *
	 * @param string[] $fieldNames
	 * @throws FieldNotWritableException
	 */
	private function assertFieldsWritable(array $fieldNames): void
	{
		foreach ($fieldNames as $fieldName)
		{
			if (!in_array($fieldName, self::WRITABLE_FIELDS, true))
			{
				throw new FieldNotWritableException((string)$fieldName);
			}
		}
	}

	/**
	 * A blank name is refused before the boundary is touched at all, and blanks are trimmed off first
	 * because the boundary trims before it looks: `\CCrmStatus::Update()` drops a name that comes out
	 * empty from the write instead of refusing it, so a rename to nothing would report success and
	 * leave the stored name where it is, while `\CCrmStatus::Add()` stores the trimmed name and lets
	 * the required-field check of the ORM answer for it. One rule for both, and it is the domain's:
	 * a stage without a name is not a stage.
	 *
	 * @param array<string, mixed> $fields
	 * @throws FieldValueNotAllowedException
	 */
	private static function assertFieldValuesAllowed(array $fields): void
	{
		if (array_key_exists('name', $fields) && trim((string)$fields['name']) === '')
		{
			throw new FieldValueNotAllowedException('name');
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function toBoundaryFields(array $fields): array
	{
		$boundaryFields = [];
		foreach (self::BOUNDARY_FIELD_BY_DOMAIN_FIELD as $fieldName => $boundaryFieldName)
		{
			if (array_key_exists($fieldName, $fields))
			{
				$boundaryFields[$boundaryFieldName] = (string)$fields[$fieldName];
			}
		}

		return $boundaryFields;
	}

	/**
	 * Where a stage the caller gave no position goes: right after the last stage in process
	 * semantics, and in any case ahead of the success stage. Behind the success stage the boundary
	 * either refuses the stage or silently makes it a failure one
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}), which is not what adding a stage to a funnel
	 * means.
	 */
	private function sortForNewStage(int $categoryId): int
	{
		$lastProcessSort = 0;
		$successSort = null;
		foreach ($this->getAllByCategory($categoryId) as $stage)
		{
			if (PhaseSemantics::isSuccess($stage->semantics))
			{
				$successSort = $stage->sort;
			}
			elseif (!PhaseSemantics::isFinal($stage->semantics))
			{
				$lastProcessSort = $stage->sort;
			}
		}

		$sort = $lastProcessSort + self::SORT_STEP;
		if ($successSort !== null && $sort >= $successSort)
		{
			// The step does not fit between the last process stage and the success one. Landing on the
			// sort of a process stage is fine: the record id breaks the tie and the new stage still
			// comes after it.
			$sort = max(1, $successSort - 1);
		}

		return $sort;
	}

	/**
	 * `\CCrmStatus` keeps no result: `Add()` answers `false` and `Update()` answers the id it was
	 * given whatever happened, so the text of the first error is all the boundary leaves behind. It
	 * travels verbatim - the phrase is what
	 * {@see \Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper} recognizes a
	 * refusal by, and an empty one becomes the general refusal of the domain there.
	 *
	 * Nothing has been stored when the boundary refuses this way: it turns a write down either in its
	 * own checks or in the `onBefore*` event of the ORM, which collects the refusal and returns before
	 * the statement ({@see \Bitrix\Main\ORM\Data\DataManager::update()}). The refusal says so, so that
	 * undoing it costs no more than it has to
	 * ({@see StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}).
	 */
	private static function refusalOf(\CCrmStatus $boundary): Result
	{
		return (new Result())
			->addError(new Error((string)$boundary->GetLastError()))
			->setData([self::DATA_KEY_WRITE_REACHED_STORAGE => false])
		;
	}

	private function written(int $recordId, int $categoryId): Result
	{
		return (new Result())->setData([
			self::DATA_KEY_STAGE => $this->requireStored($recordId, $categoryId),
		]);
	}

	/**
	 * The result of a write is read back from storage instead of being assembled from the input: the
	 * boundary generates the identifier of a new stage, normalizes the colour and stores process
	 * semantics as no semantics at all.
	 */
	private function requireStored(int $recordId, int $categoryId): StageData
	{
		$stage = StatusTable::getById($recordId)->fetchObject();
		if ($stage === null)
		{
			throw new ObjectNotFoundException(
				"Stage {$recordId} of entity type {$this->entityTypeId} is not readable right after a successful write."
			);
		}

		return $this->toData($stage, $categoryId);
	}

	/**
	 * The factory to write the stages of $categoryId through. An entity type without stages has no
	 * stage to write, and a category the entity type does not have is refused the way the category
	 * boundary refuses it - writing to it would leave stages behind a funnel nobody can reach. A read
	 * is more forgiving than that: it answers an empty set where a write refuses.
	 */
	private function requireFactory(int $categoryId): Factory
	{
		$factory = $this->stagesFactory();
		if ($factory === null)
		{
			throw new InvalidOperationException("Entity type {$this->entityTypeId} has no stages to write.");
		}

		if ($factory->isCategoriesSupported() && !$factory->isCategoryExists($categoryId))
		{
			throw new EntryException($this->entityTypeId, $categoryId, [], EntryException::NOT_FOUND);
		}

		return $factory;
	}

	private function requireStage(Factory $factory, int $categoryId, string $stageId): EO_Status
	{
		$stage = $this->knownStage($categoryId, $stageId) ?? $factory->getStageFromCategory($categoryId, $stageId);
		if ($stage === null)
		{
			throw $this->stageNotFound($categoryId, $stageId);
		}

		$this->rememberRecordId($categoryId, $stage);

		return $stage;
	}

	/**
	 * The stage read by the record id {@see self::$recordIdByStage} holds for it, or `null` when there
	 * is no such record id - and when the record it names is no longer that stage.
	 *
	 * The row is read rather than trusted because the map can outlive storage: a write that filled it
	 * may be undone by a rollback of the scenario around it
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer}), and the record id it handed
	 * out then names a row that is gone. Such an entry is dropped and the caller goes back to the
	 * boundary, which is the slow answer and always the right one.
	 */
	private function knownStage(int $categoryId, string $stageId): ?EO_Status
	{
		$key = self::stageKey($categoryId, $stageId);
		$recordId = $this->recordIdByStage[$key] ?? null;
		if ($recordId === null)
		{
			return null;
		}

		$stage = StatusTable::getById($recordId)->fetchObject();
		if ($stage === null || $stage->getStatusId() !== $stageId)
		{
			unset($this->recordIdByStage[$key]);

			return null;
		}

		return $stage;
	}

	private function rememberRecordId(int $categoryId, EO_Status $stage): void
	{
		$this->recordIdByStage[self::stageKey($categoryId, $stage->getStatusId())] = (int)$stage->getId();
	}

	private static function stageKey(int $categoryId, string $stageId): string
	{
		return $categoryId . ':' . $stageId;
	}

	private function stageNotFound(int $categoryId, string $stageId): ObjectNotFoundException
	{
		return new ObjectNotFoundException(
			"Category {$categoryId} of entity type {$this->entityTypeId} has no stage {$stageId}."
		);
	}

	/**
	 * Whatever the outcome of a write is, the stages memoized by the stage broker of the entity type
	 * no longer match storage. The boundary purges its own caches from the ORM events of
	 * {@see StatusTable} but knows nothing about that memo, and the broker is a singleton holding it
	 * for the whole request.
	 */
	private function forgetMemoizedStages(Factory $factory): void
	{
		$factory->purgeStagesCache();
	}

	/**
	 * `null` whenever the stages of $categoryId cannot be read without changing state: an unknown
	 * entity type, an entity type without stages, or a non-positive category id the entity type does
	 * not actually have - {@see \Bitrix\Crm\Service\Factory\Dynamic::getStagesEntityId()} creates a
	 * category and plays its scenarios when it gets one. Deal is the case where `0` is a category the
	 * entity type does have: its virtual one.
	 */
	private function getFactory(int $categoryId): ?Factory
	{
		$factory = $this->stagesFactory();
		if ($factory === null)
		{
			return null;
		}

		return $this->hasCategory($factory, $categoryId) ? $factory : null;
	}

	/**
	 * Whether the entity type actually has the category $categoryId. See {@see self::getFactory()} for
	 * why a non-positive one has to be asked about at all.
	 */
	private function hasCategory(Factory $factory, int $categoryId): bool
	{
		return $categoryId > 0 || !$factory->isCategoriesSupported() || $factory->isCategoryExists($categoryId);
	}

	/**
	 * The factory of the entity type this repository is bound to, or `null` when that entity type has
	 * no stages at all.
	 *
	 * An entity type outside {@see EntityType} is refused before the factory is asked anything:
	 * `isStagesSupported()` is the inherited `true` for Order, whose `getStagesEntityId()` throws
	 * {@see \Bitrix\Main\NotSupportedException}.
	 */
	private function stagesFactory(): ?Factory
	{
		if (!EntityType::isValid($this->entityTypeId))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($this->entityTypeId);

		return ($factory === null || !$factory->isStagesSupported()) ? null : $factory;
	}

	private function toData(EO_Status $stage, int $categoryId): StageData
	{
		// Every read and every write of a single stage passes through here, which is what keeps the map
		// filled without a step of its own.
		$this->rememberRecordId($categoryId, $stage);

		return new StageData(
			stageId: $stage->getStatusId(),
			categoryId: $categoryId,
			name: $stage->getName(),
			color: $stage->getColor(),
			semantics: $this->resolveSemantics($stage->getSemantics()),
			sort: $stage->getSort(),
			isSystem: $stage->getSystem(),
		);
	}

	/**
	 * Process semantics is stored as `null` ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}); the rule
	 * repeats {@see Factory::getStageSemantics()}.
	 */
	private function resolveSemantics(?string $semantics): string
	{
		return PhaseSemantics::isDefined($semantics) ? $semantics : PhaseSemantics::PROCESS;
	}
}

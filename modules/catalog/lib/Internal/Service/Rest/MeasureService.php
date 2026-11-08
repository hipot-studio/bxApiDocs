<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\MeasureDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\MeasureTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

final readonly class MeasureService
{
	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(MeasureDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$entity = $this->repository->getEntityById($id, $select);
		if ($entity === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $entity]);
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result
	{
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'items' => $this->repository->getCollectionWithExtraFilters(
				$select,
				$filter,
				$order,
				$pagination,
			),
		]);
	}

	public function add(MeasureDto $dto): Result
	{
		$access = $this->accessSupport->ensureMeasureModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);

		$check = $this->checkFields($fields, null);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$addResult = MeasureTable::add($fields);
		}
		catch (DuplicateEntryException)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validation(new Error(
				'A measure with code \'' . (string)($fields['CODE'] ?? '') . '\' already exists.'
			));
		}
		catch (SqlQueryException)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validation(new Error('Internal error adding measure. Try adding again.'));
		}

		if (!$addResult->isSuccess())
		{
			$connection->rollbackTransaction();

			return ServiceResult::validation($addResult->getErrors());
		}

		$connection->commitTransaction();

		return $this->get((int)$addResult->getId());
	}

	public function update(int $id, MeasureDto $dto): Result
	{
		$access = $this->accessSupport->ensureMeasureModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->exists($id))
		{
			return ServiceResult::notFound($id);
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);

		$check = $this->checkFields($fields, $id);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$updateResult = MeasureTable::update($id, $fields);
		if (!$updateResult->isSuccess())
		{
			return ServiceResult::validation($updateResult->getErrors());
		}

		return $this->get($id);
	}

	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensureMeasureModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->exists($id))
		{
			return ServiceResult::notFound($id);
		}

		$deleteResult = MeasureTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return ServiceResult::validation($deleteResult->getErrors());
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @param array<string, mixed> $fields ORM fields of the written measure
	 * @param int|null $excludeId primary of the measure being updated
	 */
	private function checkFields(array $fields, ?int $excludeId): Result
	{
		if (isset($fields['CODE']) && $this->existsByCode((int)$fields['CODE'], $excludeId))
		{
			return ServiceResult::validation(new Error('Duplicate entry for key [code]'));
		}

		// Default measure is unique; the ORM field applies 'N' on its own when isDefault is omitted.
		if (($fields['IS_DEFAULT'] ?? null) === true && $this->existsDefault($excludeId))
		{
			return ServiceResult::validation(new Error('default value can be set once [isDefault]'));
		}

		return new Result();
	}

	private function exists(int $id): bool
	{
		return $this->existsByFilter(['=ID' => $id]);
	}

	private function existsByCode(int $code, ?int $excludeId): bool
	{
		return $this->existsByFilter($this->excludeId(['=CODE' => $code], $excludeId));
	}

	private function existsDefault(?int $excludeId): bool
	{
		return $this->existsByFilter($this->excludeId(['=IS_DEFAULT' => true], $excludeId));
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private function excludeId(array $filter, ?int $excludeId): array
	{
		if ($excludeId !== null)
		{
			$filter['!=ID'] = $excludeId;
		}

		return $filter;
	}

	/**
	 * @param array<string, mixed> $filter
	 */
	private function existsByFilter(array $filter): bool
	{
		return MeasureTable::getRow([
			'select' => ['ID'],
			'filter' => $filter,
		]) !== null;
	}
}

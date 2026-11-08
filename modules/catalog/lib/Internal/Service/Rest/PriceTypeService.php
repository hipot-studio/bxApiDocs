<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\PriceTypeDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalogGroup;

/**
 * Writes go through the legacy domain facade (validation, cascade cleanup of the related tables,
 * the single base price type invariant and the events the current REST integration is bound to),
 * so errors arrive as a global application exception instead of a structured Result.
 */
final readonly class PriceTypeService
{
	use ApplicationErrors;

	/**
	 * The domain demands a non-empty set of access groups on add, and administrators are the
	 * smallest one that satisfies it. Wider access belongs to `catalog.pricetype.group.*`,
	 * where the client asks for it explicitly.
	 */
	private const CREATION_ACCESS_GROUPS = [1];

	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(PriceTypeDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
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
		$access = $this->accessSupport->ensurePriceTypeRead();
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

	public function add(PriceTypeDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);

		$checkResult = $this->checkFields($fields);
		if (!$checkResult->isSuccess())
		{
			return ServiceResult::validation($checkResult->getErrors());
		}

		$fields = $this->toDomainFields($fields);
		$fields['USER_GROUP'] = self::CREATION_ACCESS_GROUPS;
		$fields['USER_GROUP_BUY'] = self::CREATION_ACCESS_GROUPS;

		$this->resetApplicationException();
		$id = (int)CCatalogGroup::Add($fields);
		if ($id <= 0)
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error adding price type'),
			]);
		}

		return $this->get($id);
	}

	/**
	 * Access groups are never sent from here: they belong to `catalog.pricetype.group.*`, and the
	 * domain rewrites them only for a key it was given, so a partial update leaves them intact.
	 */
	public function update(int $id, PriceTypeDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->exists($id))
		{
			return ServiceResult::notFound($id);
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		if ($fields === [])
		{
			return ServiceResult::validation(
				new Error('At least one field to update must be provided', 'fields'),
			);
		}

		$checkResult = $this->checkFields($fields);
		if (!$checkResult->isSuccess())
		{
			return ServiceResult::validation($checkResult->getErrors());
		}

		$this->resetApplicationException();
		if (!CCatalogGroup::Update($id, $this->toDomainFields($fields)))
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error updating price type'),
			]);
		}

		return $this->get($id);
	}

	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->exists($id))
		{
			return ServiceResult::notFound($id);
		}

		$this->resetApplicationException();
		if (!CCatalogGroup::Delete($id))
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error deleting price type'),
			]);
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * Tells a missing record from a refusal of the domain, which reports both the same way.
	 */
	private function exists(int $id): bool
	{
		$select = SelectStructure::create(['id'], PriceTypeDto::class);

		return $this->repository->getEntityById($id, $select) !== null;
	}

	/**
	 * None of `base`, `name` and `sort` has an empty state in the contract, while the DTO types allow
	 * an explicit null: the domain reads anything but a literal 'Y' as 'N', so a null `base` would
	 * silently clear the flag, a null `name` would reach trim() there before being refused, and a
	 * null `sort` would be cast to 0 and then replaced by the default, overwriting the stored order.
	 * Only the explicit null is refused here: a non-positive `sort` is still normalised by the
	 * domain, and that is the V1 behaviour.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function checkFields(array $fields): Result
	{
		$result = new Result();

		if (array_key_exists('BASE', $fields) && $fields['BASE'] === null)
		{
			$result->addError(new Error('The field base must be true or false', 'base'));
		}

		if (array_key_exists('NAME', $fields) && $fields['NAME'] === null)
		{
			$result->addError(new Error('The field name must not be null', 'name'));
		}

		if (array_key_exists('SORT', $fields) && $fields['SORT'] === null)
		{
			$result->addError(new Error('The field sort must be an integer', 'sort'));
		}

		return $result;
	}

	/**
	 * The domain stores BASE as a character flag and understands nothing else, so a boolean would
	 * be read as 'N' and the flag would simply not be set; an unset field stays unset.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function toDomainFields(array $fields): array
	{
		if (array_key_exists('BASE', $fields))
		{
			$fields['BASE'] = $fields['BASE'] ? 'Y' : 'N';
		}

		return $fields;
	}

	/**
	 * The domain never clears the global application exception before throwing its own, while
	 * {@see ApplicationErrors} clears it only when a failing call reads it. A message left behind
	 * by earlier code would therefore be reported as the error of this call.
	 */
	private function resetApplicationException(): void
	{
		global $APPLICATION;

		$APPLICATION->ResetException();
	}
}

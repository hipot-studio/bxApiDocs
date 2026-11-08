<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\VatDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalogVat;

/**
 * Writes go through the legacy domain facade (validation, events, single system rate invariant),
 * so errors arrive as a global application exception instead of a structured Result.
 */
final readonly class VatService
{
	use ApplicationErrors;

	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(VatDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureVatRead();
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
		$access = $this->accessSupport->ensureVatRead();
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

	public function add(VatDto $dto): Result
	{
		$access = $this->accessSupport->ensureVatModify();
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

		$this->resetApplicationException();
		$id = (int)CCatalogVat::Add($this->toDomainFields($fields));
		if ($id <= 0)
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error adding VAT'),
			]);
		}

		return $this->get($id);
	}

	public function update(int $id, VatDto $dto): Result
	{
		$access = $this->accessSupport->ensureVatModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if ($this->repository->getEntityById($id) === null)
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
		if (!CCatalogVat::Update($id, $this->toDomainFields($fields)))
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error updating VAT'),
			]);
		}

		return $this->get($id);
	}

	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensureVatModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if ($this->repository->getEntityById($id) === null)
		{
			return ServiceResult::notFound($id);
		}

		$this->resetApplicationException();
		if (!CCatalogVat::Delete($id))
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Error deleting VAT'),
			]);
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * Neither `active` nor `sort` has an empty state in the contract, so an explicit null is rejected
	 * instead of being forwarded: the domain would substitute its own value on add and drop the field
	 * on update, either way answering success to a request it did not carry out.
	 * The remaining writable fields need no check here — the domain answers a null `name` or `rate`
	 * with an error of its own.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function checkFields(array $fields): Result
	{
		$result = new Result();

		if (array_key_exists('ACTIVE', $fields) && $fields['ACTIVE'] === null)
		{
			$result->addError(new Error('The field active must be true or false', 'active'));
		}

		if (array_key_exists('SORT', $fields) && $fields['SORT'] === null)
		{
			$result->addError(new Error('The field sort must be an integer', 'sort'));
		}

		return $result;
	}

	/**
	 * The domain stores ACTIVE as a character flag: on add anything but a literal 'N' becomes 'Y',
	 * on update anything but 'Y' or 'N' is dropped and the previous value survives.
	 * A boolean would therefore be misread, so it is converted here; an unset field stays unset.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function toDomainFields(array $fields): array
	{
		if (array_key_exists('ACTIVE', $fields))
		{
			$fields['ACTIVE'] = $fields['ACTIVE'] ? 'Y' : 'N';
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

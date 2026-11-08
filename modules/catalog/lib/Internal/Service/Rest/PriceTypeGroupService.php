<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\GroupAccessTable;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Infrastructure\Rest\Dto\PriceTypeGroupDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Main\Error;
use Bitrix\Main\GroupTable as MainGroupTable;
use Bitrix\Main\Result;

final readonly class PriceTypeGroupService
{
	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(PriceTypeGroupDto::class);
	}

	public function add(PriceTypeGroupDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$checkFieldsResult = $this->checkFields($fields);
		if (!$checkFieldsResult->isSuccess())
		{
			return ServiceResult::validation($checkFieldsResult->getErrors());
		}

		$addResult = GroupAccessTable::add($fields);
		if (!$addResult->isSuccess())
		{
			return ServiceResult::validation($addResult->getErrors());
		}

		return $this->get((int)$addResult->getId());
	}

	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = GroupAccessTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$deleteResult = GroupAccessTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return ServiceResult::validation($deleteResult->getErrors());
		}

		return ServiceResult::success(['result' => true]);
	}

	public function get(int $id): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$entity = $this->repository->getEntityById($id);
		if ($entity === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $entity]);
	}

	private function checkFields(array $fields): Result
	{
		$result = new Result();

		$priceTypeId = (int)($fields['CATALOG_GROUP_ID'] ?? 0);
		$priceType = GroupTable::getById($priceTypeId)->fetch();
		if (!$priceType)
		{
			$result->addError(new Error('The specified price type does not exist'));
		}

		$groupId = (int)($fields['GROUP_ID'] ?? 0);
		$group = MainGroupTable::getById($groupId)->fetch();
		if (!$group)
		{
			$result->addError(new Error('The specified group does not exist'));
		}

		$accessTypeValues = [GroupAccessTable::ACCESS_BUY, GroupAccessTable::ACCESS_VIEW];
		$access = $fields['ACCESS'] ?? null;
		if (!in_array($access, $accessTypeValues, true))
		{
			$result->addError(
				new Error(
					'Invalid access type provided. The available values are: '
					. implode(', ', $accessTypeValues)
				)
			);
		}

		$exists = (bool)GroupAccessTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=CATALOG_GROUP_ID' => $fields['CATALOG_GROUP_ID'] ?? null,
				'=GROUP_ID' => $fields['GROUP_ID'] ?? null,
				'=ACCESS' => $fields['ACCESS'] ?? null,
			],
		]);

		if ($exists)
		{
			$result->addError(new Error('The specified access type for this group already exists'));
		}

		return $result;
	}
}

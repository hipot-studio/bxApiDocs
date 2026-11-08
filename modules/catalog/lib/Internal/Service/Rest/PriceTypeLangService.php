<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Controller\ErrorCode;
use Bitrix\Catalog\GroupLangTable;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Infrastructure\Rest\Dto\PriceTypeLangDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LanguageTable;
use Bitrix\Main\Result;

final readonly class PriceTypeLangService
{
	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(PriceTypeLangDto::class);
	}

	/**
	 * @return Result with data key `languages`
	 */
	public function getLanguages(): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'languages' => LanguageTable::getList([
				'select' => ['ACTIVE', 'NAME', 'LID'],
				'order' => ['LID' => 'ASC'],
			])->fetchAll(),
		]);
	}

	public function add(PriceTypeLangDto $dto): Result
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

		$addResult = GroupLangTable::add($fields);
		if (!$addResult->isSuccess())
		{
			return ServiceResult::validation($addResult->getErrors());
		}

		return $this->get((int)$addResult->getId());
	}

	public function update(int $id, PriceTypeLangDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = GroupLangTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$checkFieldsResult = $this->checkFields($fields);
		if (!$checkFieldsResult->isSuccess())
		{
			return ServiceResult::validation($checkFieldsResult->getErrors());
		}

		$updateResult = GroupLangTable::update($id, $fields);
		if (!$updateResult->isSuccess())
		{
			return ServiceResult::validation($updateResult->getErrors());
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

		$row = GroupLangTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$deleteResult = GroupLangTable::delete($id);
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

		if (isset($fields['CATALOG_GROUP_ID']))
		{
			$priceTypeId = (int)$fields['CATALOG_GROUP_ID'];
			$priceType = GroupTable::getById($priceTypeId)->fetch();
			if (!$priceType)
			{
				$result->addError(new Error(
					'The specified price type does not exist',
					ErrorCode::PRICE_TYPE_ENTITY_NOT_EXISTS,
				));
			}
		}

		if (isset($fields['LANG']))
		{
			$language = LanguageTable::getById($fields['LANG'])->fetch();
			if (!$language)
			{
				$result->addError(new Error(
					'The specified language does not exist',
					ErrorCode::PRICE_TYPE_LANG_LANGUAGE_NOT_EXISTS,
				));
			}
		}

		return $result;
	}
}

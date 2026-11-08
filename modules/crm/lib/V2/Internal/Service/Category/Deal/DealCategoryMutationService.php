<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Deal;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Logger\LoggerFactory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

Loc::loadMessages(__FILE__);

final class DealCategoryMutationService
{
	private readonly \Closure $isTariffLimitExceeded;
	private readonly LoggerInterface $logger;
	private readonly CategoryAccess $categoryAccess;

	public function __construct(
		private readonly Factory $factory,
		private readonly UserPermissions $userPermissions,
		?\Closure $isTariffLimitExceeded = null,
		?LoggerInterface $logger = null,
		?CategoryAccess $categoryAccess = null,
	)
	{
		$this->isTariffLimitExceeded = $isTariffLimitExceeded
			?? static fn(): bool => RestrictionManager::getDealCategoryLimitRestriction()->isExceeded();
		$this->logger = $logger ?? LoggerFactory::create('deal-category-mutation');
		$this->categoryAccess = $categoryAccess ?? new CategoryAccess();
	}

	public function add(array $fields): Result
	{
		$accessResult = $this->checkAccess(null);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if (($fields['isSystem'] ?? null) !== 'N')
		{
			return $this->createErrorResult(
				new Error('A user deal category must have isSystem set to N.', ErrorCode::INVALID_ARG_VALUE),
			);
		}

		$fieldsResult = $this->normalizeFields($fields);
		if (!$fieldsResult->isSuccess())
		{
			return $fieldsResult;
		}

		try
		{
			if (($this->isTariffLimitExceeded)())
			{
				return $this->createErrorResult(
					new Error(
						(string)(Loc::getMessage('CRM_FEATURE_RESTRICTION_ERROR') ?: 'The action is restricted by tariff.'),
						ErrorCode::RESTRICTED_BY_TARIFF,
					),
				);
			}

			$category = $this->factory->createCategory();
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'add');

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		return $this->save($category, $fieldsResult->getData());
	}

	public function update(int $id, array $fields): Result
	{
		$accessResult = $this->checkAccess($id);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		try
		{
			$category = $this->factory->getCategory($id);
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'get-for-update', ['categoryId' => $id]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		if ($category === null)
		{
			return $this->createErrorResult(ErrorCode::getNotFoundError());
		}

		if (!array_key_exists('name', $fields))
		{
			$fields['name'] = $category->getName();
		}

		$fieldsResult = $this->normalizeFields($fields);
		if (!$fieldsResult->isSuccess())
		{
			return $fieldsResult;
		}

		return $this->save($category, $fieldsResult->getData(), $id);
	}

	public function delete(int $id): Result
	{
		$accessResult = $this->checkAccess($id);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if ($id === 0)
		{
			return $this->createErrorResult(
				new Error('The default deal category cannot be deleted.', ErrorCode::REMOVING_DISABLED),
			);
		}

		try
		{
			$category = $this->factory->getCategory($id);
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'get-for-delete', ['categoryId' => $id]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		if ($category === null)
		{
			return $this->createErrorResult(ErrorCode::getNotFoundError());
		}

		if ($category->getIsSystem())
		{
			return $this->createErrorResult(
				new Error('A system category cannot be deleted.', ErrorCode::REMOVING_DISABLED),
			);
		}

		try
		{
			$deleteResult = $category->delete();
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'delete', ['categoryId' => $id]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		return $deleteResult;
	}

	private function checkAccess(?int $categoryId): Result
	{
		if (
			!$this->categoryAccess->canWriteCategory(
				\CCrmOwnerType::Deal,
				$this->userPermissions->getUserId(),
				$categoryId,
			)
		)
		{
			return $this->createErrorResult(ErrorCode::getAccessDeniedError());
		}

		return new Result();
	}

	private function normalizeFields(array $fields): Result
	{
		if (!array_key_exists('name', $fields) || !is_string($fields['name']))
		{
			return $this->createErrorResult(
				new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_MUTATION_NAME_REQUIRED'),
					ErrorCode::INVALID_ARG_VALUE,
				),
			);
		}

		$name = trim($fields['name']);
		if ($name === '')
		{
			return $this->createErrorResult(
				new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_MUTATION_NAME_REQUIRED'),
					ErrorCode::INVALID_ARG_VALUE,
				),
			);
		}

		$normalizedFields = ['name' => $name];
		if (array_key_exists('sort', $fields))
		{
			$sort = $this->normalizeSort($fields['sort']);
			if ($sort === null)
			{
				return $this->createErrorResult(
					new Error(
						Loc::getMessage('CRM_V2_DEAL_CATEGORY_MUTATION_INVALID_SORT'),
						ErrorCode::INVALID_ARG_VALUE,
					),
				);
			}

			$normalizedFields['sort'] = $sort;
		}

		return (new Result())->setData($normalizedFields);
	}

	private function normalizeSort(mixed $sort): ?int
	{
		if (is_int($sort))
		{
			return $sort >= 0 ? $sort : null;
		}

		if (!is_string($sort))
		{
			return null;
		}

		$sort = trim($sort);
		if ($sort === '' || preg_match('/^\d+$/D', $sort) !== 1)
		{
			return null;
		}

		$normalizedSort = filter_var($sort, FILTER_VALIDATE_INT);

		return is_int($normalizedSort) && $normalizedSort >= 0 ? $normalizedSort : null;
	}

	private function save(Category $category, array $fields, ?int $categoryId = null): Result
	{
		try
		{
			$category->setName($fields['name']);
			if (isset($fields['sort']))
			{
				$category->setSort($fields['sort']);
			}

			$saveResult = $category->save();
		}
		catch (\Throwable $exception)
		{
			$operationContext = $categoryId === null ? [] : ['categoryId' => $categoryId];
			$this->logUnexpectedException($exception, 'save', $operationContext);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		if (!$saveResult->isSuccess())
		{
			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		return (new Result())->setData(['category' => $category]);
	}

	private function logUnexpectedException(
		\Throwable $exception,
		string $operation,
		array $operationContext = [],
	): void
	{
		$this->logger->error('Unexpected deal category mutation error.', [
			'operation' => $operation,
			'exception' => $exception,
			...$operationContext,
		]);
	}

	private function createErrorResult(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}

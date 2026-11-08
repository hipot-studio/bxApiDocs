<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Deal;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Logger\LoggerFactory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Internal\Entity\Category\Deal\BatchDeleteState;
use Bitrix\Crm\V2\Internal\Repository\Category\Deal\DealCategoryRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\Deal\BatchDeleteStateRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

Loc::loadMessages(__FILE__);

final class BatchDeleteService
{
	public const BATCH_SIZE = 10;

	private const SCOPE_SELECTED = 'selected';
	private const SCOPE_ALL = 'all';
	private const ITEM_ERROR_DEFAULT_CATEGORY = 'DEFAULT_CATEGORY';
	private const ITEM_ERROR_DELETE_FAILED = 'DELETE_FAILED';

	private readonly BatchDeleteStateRepository $stateRepository;
	private readonly DealCategoryRepository $categoryRepository;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly Factory $factory,
		private readonly UserPermissions $userPermissions,
		?BatchDeleteStateRepository $stateRepository = null,
		?LoggerInterface $logger = null,
		?DealCategoryRepository $categoryRepository = null,
	)
	{
		$this->stateRepository = $stateRepository ?? new BatchDeleteStateRepository();
		$this->logger = $logger ?? LoggerFactory::create('deal-category-batch-delete');
		$this->categoryRepository = $categoryRepository ?? new DealCategoryRepository();
	}

	public function process(
		string $processToken,
		int $ownerUserId,
		?string $scope = null,
		array $ids = [],
	): Result
	{
		if (!$this->isValidProcessToken($processToken) || $ownerUserId <= 0)
		{
			return $this->createErrorResult(
				new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_INVALID_PROCESS_TOKEN'),
					ErrorCode::INVALID_ARG_VALUE,
				),
			);
		}

		try
		{
			$context = $this->loadOrCreateProcessContext($processToken, $ownerUserId, $scope, $ids);
			if ($context instanceof Result)
			{
				return $context;
			}

			return $this->processState($processToken, $context['state'], $context['ids']);
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'process', [
				'processToken' => $processToken,
				'ownerUserId' => $ownerUserId,
			]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}
	}

	public function cancel(string $processToken, int $ownerUserId): Result
	{
		if (!$this->isValidProcessToken($processToken) || $ownerUserId <= 0)
		{
			return $this->createErrorResult(
				new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_INVALID_PROCESS_TOKEN'),
					ErrorCode::INVALID_ARG_VALUE,
				),
			);
		}

		try
		{
			if (!$this->stateRepository->has($processToken))
			{
				return $this->createErrorResult(ErrorCode::getNotFoundError());
			}

			$accessResult = $this->checkStateAccess($processToken, $ownerUserId);
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}

			if ($this->stateRepository->isTerminal($processToken))
			{
				return $this->createTerminalResult($processToken);
			}

			$state = $this->stateRepository->load($processToken);
			if ($state === null)
			{
				return $this->createErrorResult(ErrorCode::getGeneralError());
			}

			$data = $this->buildResponse($state, 'COMPLETED');
			$this->stateRepository->markTerminal($processToken, $ownerUserId, $data);

			return (new Result())->setData($data);
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'cancel', [
				'processToken' => $processToken,
				'ownerUserId' => $ownerUserId,
			]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}
	}

	private function loadOrCreateProcessContext(
		string $processToken,
		int $ownerUserId,
		?string $scope,
		array $ids,
	): array|Result
	{
		if ($this->stateRepository->has($processToken))
		{
			$accessResult = $this->checkStateAccess($processToken, $ownerUserId);
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}

			if ($this->stateRepository->isTerminal($processToken))
			{
				return $this->createTerminalResult($processToken);
			}

			$state = $this->stateRepository->load($processToken);
			if ($state === null)
			{
				return $this->createErrorResult(ErrorCode::getGeneralError());
			}

			$selectionResult = $this->prepareSelection($scope, $ids);
			if (!$selectionResult->isSuccess())
			{
				return $selectionResult;
			}
			$selection = $selectionResult->getData();
			if (
				$state->getScope() !== $selection['scope']
				|| $state->getSelectionHash() !== $selection['hash']
			)
			{
				return $this->createInvalidScopeResult();
			}

			return ['state' => $state, 'ids' => $selection['ids']];
		}

		$accessResult = $this->checkCurrentAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}
		if ($scope === null)
		{
			return $this->createErrorResult(ErrorCode::getNotFoundError());
		}

		$selectionResult = $this->prepareSelection($scope, $ids);
		if (!$selectionResult->isSuccess())
		{
			return $selectionResult;
		}
		$selection = $selectionResult->getData();
		$isAll = $selection['scope'] === self::SCOPE_ALL;

		$state = BatchDeleteState::create(
			$ownerUserId,
			$selection['scope'],
			$selection['hash'],
			$isAll ? $this->categoryRepository->countAll() : count($selection['ids']),
			$isAll ? $this->categoryRepository->fetchMaxId() : null,
		);
		$this->stateRepository->save($processToken, $state);

		return ['state' => $state, 'ids' => $selection['ids']];
	}

	private function processState(string $processToken, BatchDeleteState $state, array $requestIds): Result
	{
		$batchIds = $this->getBatchIds($state, $requestIds);
		$categories = $this->categoryRepository->fetchByIds($batchIds);
		foreach ($batchIds as $id)
		{
			if ($id === 0)
			{
				$this->finishWithError(
					$state,
					$id,
					$this->createFallbackName($id),
					self::ITEM_ERROR_DEFAULT_CATEGORY,
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_DEFAULT_CATEGORY'),
				);
				continue;
			}

			$category = $categories[$id] ?? null;
			if ($category === null)
			{
				$this->finishWithError(
					$state,
					$id,
					$this->createFallbackName($id),
					ErrorCode::NOT_FOUND,
					ErrorCode::getNotFoundError()->getMessage(),
				);

				continue;
			}

			$name = $this->getCategoryName($category, $id);
			if (!$this->canDeleteCategory($category, $id))
			{
				$this->finishWithError(
					$state,
					$id,
					$name,
					ErrorCode::ACCESS_DENIED,
					ErrorCode::getAccessDeniedError()->getMessage(),
				);
				continue;
			}

			$deleteResult = $this->deleteCategory($category, $id);
			if (!$deleteResult->isSuccess())
			{
				$errorMessages = array_values(array_filter($deleteResult->getErrorMessages()));
				$this->logger->error('Failed to delete deal category.', [
					'categoryId' => $id,
					'ownerUserId' => $state->getOwnerUserId(),
					'errors' => $errorMessages,
				]);
				$message = implode(' ', $errorMessages);
				if ($message === '')
				{
					$message = ErrorCode::getGeneralError()->getMessage();
				}

				$this->finishWithError(
					$state,
					$id,
					$name,
					self::ITEM_ERROR_DELETE_FAILED,
					$message,
				);
				continue;
			}

			$state->finishSuccess($id);
		}

		$isCompleted = $state->isComplete() || count($batchIds) < self::BATCH_SIZE;
		$status = $isCompleted ? 'COMPLETED' : 'PROGRESS';
		$data = $this->buildResponse($state, $status);
		if ($isCompleted)
		{
			$this->stateRepository->markTerminal($processToken, $state->getOwnerUserId(), $data);
		}
		else
		{
			$this->stateRepository->save($processToken, $state);
		}

		return (new Result())->setData($data);
	}

	private function checkStateAccess(string $processToken, int $ownerUserId): Result
	{
		if ($this->stateRepository->getOwnerUserId($processToken) !== $ownerUserId)
		{
			return $this->createErrorResult(ErrorCode::getAccessDeniedError());
		}

		return $this->checkCurrentAccess();
	}

	private function createTerminalResult(string $processToken): Result
	{
		$payload = $this->stateRepository->getTerminalPayload($processToken);
		if ($payload === null)
		{
			return $this->createErrorResult(ErrorCode::getGeneralError());
		}

		return (new Result())->setData($payload);
	}

	private function checkCurrentAccess(): Result
	{
		return $this->canManageFunnels()
			? new Result()
			: $this->createErrorResult(ErrorCode::getAccessDeniedError());
	}

	/**
	 * A `null` $categoryId asks about the funnels of Deal as a whole, which is what the screen behind
	 * this service asks before it opens at all.
	 */
	private function canManageFunnels(?int $categoryId = null): bool
	{
		return $this->userPermissions->configuration()->canManageFunnels(
			ConfigurationCallSite::DealCategoryListAjax,
			ConfigurationScope::ofEntityType(\CCrmOwnerType::Deal, $categoryId),
		);
	}

	private function prepareSelection(?string $scope, array $ids): Result
	{
		if ($scope === self::SCOPE_ALL)
		{
			$normalizedIds = [];
		}
		elseif ($scope === self::SCOPE_SELECTED)
		{
			if ($ids === [])
			{
				return $this->createInvalidScopeResult();
			}

			$normalizedIds = [];
			foreach ($ids as $id)
			{
				if (!is_int($id) || $id < 0)
				{
					return $this->createInvalidScopeResult();
				}

				$normalizedIds[] = $id;
			}
		}
		else
		{
			return $this->createInvalidScopeResult();
		}

		$normalizedIds = array_values(array_unique($normalizedIds, SORT_NUMERIC));
		sort($normalizedIds, SORT_NUMERIC);

		return (new Result())->setData([
			'scope' => $scope,
			'ids' => $normalizedIds,
			'hash' => hash('sha256', $scope . ':' . implode(',', $normalizedIds)),
		]);
	}

	private function getBatchIds(BatchDeleteState $state, array $requestIds): array
	{
		if ($state->getScope() === self::SCOPE_ALL)
		{
			$upperId = $state->getUpperId();
			if ($upperId === null)
			{
				return [];
			}

			return $this->categoryRepository->fetchIdsAfter(
				max($state->getLastId(), 0),
				$upperId,
				self::BATCH_SIZE,
			);
		}

		$remainingIds = array_filter(
			$requestIds,
			static fn(int $id): bool => $id > $state->getLastId(),
		);

		return array_slice(array_values($remainingIds), 0, self::BATCH_SIZE);
	}

	private function createInvalidScopeResult(): Result
	{
		return $this->createErrorResult(
			new Error(
				Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_INVALID_SCOPE'),
				ErrorCode::INVALID_ARG_VALUE,
			),
		);
	}

	private function canDeleteCategory(Category $category, int $categoryId): bool
	{
		try
		{
			return
				$this->canManageFunnels($category->getId())
				&& $this->userPermissions->category()->canDelete($category)
			;
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'check-delete-permission', [
				'categoryId' => $categoryId,
			]);

			return false;
		}
	}

	private function deleteCategory(Category $category, int $categoryId): Result
	{
		try
		{
			return $category->delete();
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedException($exception, 'delete', [
				'categoryId' => $categoryId,
			]);

			return $this->createErrorResult(ErrorCode::getGeneralError());
		}
	}

	private function finishWithError(
		BatchDeleteState $state,
		int $id,
		string $name,
		string $code,
		string $message,
	): void
	{
		$state->finishError($id, $name, $code, $message);
	}

	private function buildResponse(BatchDeleteState $state, string $status): array
	{
		$itemErrors = $state->getItemErrors();
		$successCount = $state->getSuccessCount();
		$errorCount = $state->getErrorCount();

		return [
			'STATUS' => $status,
			'PROCESSED_ITEMS' => $state->getProcessedItemsCount(),
			'TOTAL_ITEMS' => $state->getTotalCount(),
			'SUMMARY_HTML' => $this->buildSummaryHtml($successCount, $errorCount, $itemErrors),
			'WARNING' => count($itemErrors) < $errorCount
				? Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_ERRORS_LIMITED', [
					'#SHOWN_COUNT#' => count($itemErrors),
					'#ERROR_COUNT#' => $errorCount,
				])
				: null,
			'successCount' => $successCount,
			'errorCount' => $errorCount,
			'itemErrors' => $itemErrors,
		];
	}

	private function buildSummaryHtml(int $successCount, int $errorCount, array $itemErrors): string
	{
		$html = '<div>'
			. $this->encode(Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_SUMMARY', [
				'#SUCCESS_COUNT#' => $successCount,
				'#ERROR_COUNT#' => $errorCount,
			]))
			. '</div>';
		if ($errorCount === 0)
		{
			return $html;
		}

		$html .= '<details data-testid="crm-deal-category-bulk-delete-errors"><summary>'
			. $this->encode(Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_ERRORS', [
				'#ERROR_COUNT#' => $errorCount,
			]))
			. '</summary><ul>';
		foreach ($itemErrors as $itemError)
		{
			$description = Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_ERROR_DESCRIPTION', [
				'#NAME#' => $itemError['name'],
				'#ID#' => $itemError['id'],
				'#MESSAGE#' => $itemError['message'],
			]);
			$html .= '<li>' . $this->encode($description) . '</li>';
		}

		return $html . '</ul></details>';
	}

	private function getCategoryName(?Category $category, int $id): string
	{
		if ($category !== null)
		{
			try
			{
				$name = trim($category->getName());
				if ($name !== '')
				{
					return $name;
				}
			}
			catch (\Throwable $exception)
			{
				$this->logUnexpectedException($exception, 'get-name', [
					'categoryId' => $id,
				]);
			}
		}

		return $this->createFallbackName($id);
	}

	private function createFallbackName(int $id): string
	{
		return $id === 0
			? DealCategory::getDefaultCategoryName()
			: Loc::getMessage('CRM_V2_DEAL_CATEGORY_BATCH_DELETE_FALLBACK_NAME', ['#ID#' => $id]);
	}

	private function isValidProcessToken(string $processToken): bool
	{
		return
			$processToken !== ''
			&& strlen($processToken) <= 255
			&& preg_match('/^[a-zA-Z0-9_.:-]+$/D', $processToken) === 1
		;
	}

	private function encode(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function logUnexpectedException(
		\Throwable $exception,
		string $operation,
		array $operationContext = [],
	): void
	{
		$this->logger->error('Unexpected deal category batch deletion error.', [
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

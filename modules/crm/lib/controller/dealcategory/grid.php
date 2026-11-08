<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller\DealCategory;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Category\Deal\BatchDeleteService;
use Bitrix\Crm\V2\Internal\Service\Category\Deal\DealCategoryMutationService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;

Loc::loadMessages(__FILE__);

class Grid extends Base
{
	public function __construct(
		?Request $request = null,
		private ?BatchDeleteService $batchDeleteService = null,
		private ?UserPermissions $userPermissions = null,
	)
	{
		parent::__construct($request);
	}

	public function configureActions(): array
	{
		$postFilters = [
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
		];

		return [
			'add' => ['+prefilters' => $postFilters],
			'update' => ['+prefilters' => $postFilters],
			'delete' => ['+prefilters' => $postFilters],
			'process' => ['+prefilters' => $postFilters],
			'cancel' => ['+prefilters' => $postFilters],
		];
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);

		return $filters;
	}

	public function addAction(array $fields): ?array
	{
		$service = $this->createDealCategoryMutationService();
		if ($service === null)
		{
			return null;
		}

		$fields['isSystem'] = 'N';
		$result = $service->add($fields);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function updateAction(int $id, array $fields): ?array
	{
		$service = $this->createDealCategoryMutationService();
		if ($service === null)
		{
			return null;
		}

		$result = $service->update($id, $fields);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function deleteAction(int $id): void
	{
		$service = $this->createDealCategoryMutationService();
		if ($service === null)
		{
			return;
		}

		$result = $service->delete($id);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	public function processAction(
		$scope = null,
		$ids = [],
		$PROCESS_TOKEN = null,
	): ?array
	{
		if (!is_string($scope) || !is_array($ids) || !is_string($PROCESS_TOKEN))
		{
			$this->addError(new Error(
				Loc::getMessage('CRM_DEAL_CATEGORY_BATCH_DELETE_INVALID_REQUEST'),
				ErrorCode::INVALID_ARG_VALUE,
			));

			return null;
		}

		$normalizedIds = $this->normalizeIds($ids);
		if ($normalizedIds === null)
		{
			$this->addError(new Error(
				Loc::getMessage('CRM_DEAL_CATEGORY_BATCH_DELETE_INVALID_IDS'),
				ErrorCode::INVALID_ARG_VALUE,
			));

			return null;
		}

		$userId = $this->getCurrentUserId();
		$service = $this->prepareBatchDeleteService($userId);
		if ($service === null)
		{
			return null;
		}

		$result = $service->process($PROCESS_TOKEN, $userId, $scope, $normalizedIds);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function cancelAction($PROCESS_TOKEN = null): ?array
	{
		if (!is_string($PROCESS_TOKEN))
		{
			$this->addError(new Error(
				Loc::getMessage('CRM_DEAL_CATEGORY_BATCH_DELETE_INVALID_PROCESS_TOKEN'),
				ErrorCode::INVALID_ARG_VALUE,
			));

			return null;
		}

		$userId = $this->getCurrentUserId();
		$service = $this->prepareBatchDeleteService($userId);
		if ($service === null)
		{
			return null;
		}

		$result = $service->cancel($PROCESS_TOKEN, $userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	protected function createDealCategoryMutationService(): ?DealCategoryMutationService
	{
		try
		{
			$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
			if ($factory === null || !$factory->isCategoriesSupported())
			{
				$this->addError(ErrorCode::getGeneralError());

				return null;
			}

			return new DealCategoryMutationService(
				$factory,
				Container::getInstance()->getUserPermissions(),
			);
		}
		catch (\Throwable)
		{
			$this->addError(ErrorCode::getGeneralError());

			return null;
		}
	}

	protected function getCurrentUserId(): int
	{
		return (int)($this->getCurrentUser() ?? CurrentUser::get())->getId();
	}

	private function prepareBatchDeleteService(int $userId): ?BatchDeleteService
	{
		try
		{
			$userPermissions = $this->userPermissions ?? Container::getInstance()->getUserPermissions($userId);
			if (!$userPermissions->isAdminForEntity(\CCrmOwnerType::Deal))
			{
				$this->addError(ErrorCode::getAccessDeniedError());

				return null;
			}

			if ($this->batchDeleteService === null)
			{
				$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
				if ($factory === null || !$factory->isCategoriesSupported())
				{
					$this->addError(ErrorCode::getGeneralError());

					return null;
				}

				$this->batchDeleteService = new BatchDeleteService($factory, $userPermissions);
			}

			return $this->batchDeleteService;
		}
		catch (\Throwable)
		{
			$this->addError(ErrorCode::getGeneralError());

			return null;
		}
	}

	private function normalizeIds(array $ids): ?array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			if (is_int($id) && $id >= 0)
			{
				$normalizedIds[] = $id;

				continue;
			}

			if (!is_string($id) || preg_match('/^\d+$/D', $id) !== 1)
			{
				return null;
			}

			$normalizedId = filter_var($id, FILTER_VALIDATE_INT);
			if (!is_int($normalizedId) || $normalizedId < 0)
			{
				return null;
			}

			$normalizedIds[] = $normalizedId;
		}

		return $normalizedIds;
	}
}

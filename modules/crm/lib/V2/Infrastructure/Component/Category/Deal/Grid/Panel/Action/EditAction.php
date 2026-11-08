<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Panel\Action;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Category\Deal\DealCategoryMutationService;
use Bitrix\Main\Error;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class EditAction extends \Bitrix\Main\Grid\Panel\Action\EditAction
{
	public function __construct(
		private ?DealCategoryMutationService $mutationService = null,
	)
	{
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		$result = new Result();
		$mutationService = $this->getMutationService();
		if ($mutationService === null)
		{
			return $result->addError(ErrorCode::getGeneralError());
		}

		foreach ($this->getRequestRows($request) as $id => $row)
		{
			if (!is_array($row))
			{
				$result->addError(new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_GRID_EDIT_INVALID_ROW'),
					ErrorCode::INVALID_ARG_VALUE,
				));

				continue;
			}

			$id = $this->normalizeId($id);
			if ($id === null)
			{
				$result->addError(new Error(
					Loc::getMessage('CRM_V2_DEAL_CATEGORY_GRID_EDIT_INVALID_ID'),
					ErrorCode::INVALID_ARG_VALUE,
				));

				continue;
			}

			$fields = [];
			if (array_key_exists('NAME', $row))
			{
				$fields['name'] = $row['NAME'];
			}
			if (array_key_exists('SORT', $row))
			{
				$fields['sort'] = $row['SORT'];
			}

			$updateResult = $mutationService->update($id, $fields);
			if (!$updateResult->isSuccess())
			{
				$result->addErrors($updateResult->getErrors());
			}
		}

		return $result;
	}

	private function getMutationService(): ?DealCategoryMutationService
	{
		if ($this->mutationService !== null)
		{
			return $this->mutationService;
		}

		$container = Container::getInstance();
		$factory = $container->getFactory(\CCrmOwnerType::Deal);
		if ($factory === null || !$factory->isCategoriesSupported())
		{
			return null;
		}

		$this->mutationService = new DealCategoryMutationService(
			$factory,
			$container->getUserPermissions(),
		);

		return $this->mutationService;
	}

	private function normalizeId(mixed $id): ?int
	{
		if (is_int($id))
		{
			return $id >= 0 ? $id : null;
		}

		if (!is_string($id) || preg_match('/^\d+$/D', $id) !== 1)
		{
			return null;
		}

		$normalizedId = filter_var($id, FILTER_VALIDATE_INT);

		return is_int($normalizedId) && $normalizedId >= 0 ? $normalizedId : null;
	}
}

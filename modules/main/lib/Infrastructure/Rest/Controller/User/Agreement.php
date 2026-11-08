<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller\User;

use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Main\Infrastructure\Rest\Dto\AgreementDto;
use Bitrix\Main\Infrastructure\Rest\Request\User\AgreementGetRequest;
use Bitrix\Main\Infrastructure\Rest\Service\AgreementListService;
use Bitrix\Main\Infrastructure\Rest\Service\AgreementService;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\SystemException;
use Bitrix\Rest\RestException as LegacyRestException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(AgreementDto::class)]
class Agreement extends AbstractController
{
	#[Scope('userconsent')]
	public function listAction(ListRequest $request, AgreementListService $agreementListService): ListResponse
	{
		$selectList = $request->select?->getList() ?? [];
		foreach (['label', 'text'] as $field)
		{
			if (in_array($field, $selectList, true))
			{
				throw new LogicException(
					new SystemException('Use main.user.agreement.get to select label or text fields.'),
				);
			}
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];
		$filter = LegacyQueryConverter::filterToLegacy($request->filter);
		if ($filter !== [])
		{
			$filter = LegacyQueryConverter::convertBoolFilterValuesToYn($filter, ['ACTIVE']);
		}

		$result = $agreementListService->getList(
			select: $selectList,
			order: LegacyQueryConverter::orderToLegacy($request->order),
			filter: $filter,
			limit: $request->pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT,
			offset: $request->pagination?->getOffset() ?? 0,
		);

		return new ListResponse($this->getDtoMapper()->mapCollection($result, $selectedFields));
	}

	#[Scope('userconsent')]
	public function getAction(AgreementGetRequest $request, AgreementService $agreementService): GetResponse
	{
		$replace = $request->replace ?? [];

		try
		{
			$rawItem = $agreementService->getById($request->id, $replace);
		}
		catch (LegacyRestException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}

		if ($replace !== [])
		{
			$rawItem['REPLACE'] = $replace;
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new GetResponse($this->getDtoMapper()->mapOne($rawItem, $selectedFields));
	}
}

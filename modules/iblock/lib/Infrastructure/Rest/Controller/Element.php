<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Controller;

use Bitrix\Iblock\Infrastructure\Rest\Dto\ElementDto;
use Bitrix\Iblock\Infrastructure\Rest\Request\Element\GetRequest;
use Bitrix\Iblock\Infrastructure\Rest\Request\Element\ListRequest;
use Bitrix\Iblock\Infrastructure\Rest\Service\ElementReadService;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(ElementDto::class)]
class Element extends AbstractController
{
	public function getAction(
		GetRequest $request,
		ElementReadService $readService,
	): GetResponse
	{
		$raw = $readService->get(
			iblockId: $request->iblock,
			elementId: (int)$request->id,
			select: $request->select,
		);

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new GetResponse($this->getDtoMapper()->mapOne($raw, $selectedFields));
	}

	public function listAction(
		ListRequest $request,
		ElementReadService $readService,
	): ListResponse
	{
		$items = $readService->list(
			iblockId: $this->resolveIblockId($request->filter),
			select: $request->select,
			filter: $request->filter,
			order: $request->order,
			pagination: $request->pagination ?? PaginationStructure::create([
				'limit' => PaginationStructure::DEFAULT_LIMIT,
			]),
		);

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new ListResponse($this->getDtoMapper()->mapCollection($items, $selectedFields));
	}

	private function resolveIblockId(?FilterStructure $filter): int
	{
		$simple = $filter?->getSimpleFilterConditions() ?? [];
		$iblockId = (int)($simple['iblockId'] ?? 0);
		if ($iblockId <= 0)
		{
			throw new RequestValidationException([
				new Error('iblockId must be a positive integer.', 'filter'),
			]);
		}

		return $iblockId;
	}
}

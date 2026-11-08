<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller;

use Bitrix\Catalog\Infrastructure\Rest\Dto\MeasureRatioDto;
use Bitrix\Catalog\Internal\Service\Rest\MeasureRatioService;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(MeasureRatioDto::class)]
class Ratio extends AbstractController
{
	public function getAction(GetRequest $request, MeasureRatioService $service): GetResponse
	{
		$id = $this->requirePositiveId($request->id);
		$data = $this->requireResultData($service->get($id, $request->select));
		/** @var EntityObject $item */
		$item = $data['item'];

		return new GetResponse($this->mapOrmEntity(MeasureRatioDto::class, $item));
	}

	public function listAction(ListRequest $request, MeasureRatioService $service): ListResponse
	{
		$data = $this->requireResultData($service->list(
			$request->select,
			$request->filter,
			$request->order,
			$request->pagination,
		));

		return new ListResponse(
			$this->mapOrmCollection(MeasureRatioDto::class, $data['items']),
		);
	}
}

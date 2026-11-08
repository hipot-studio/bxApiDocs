<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Product;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductImageDto;
use Bitrix\Catalog\Infrastructure\Rest\Request\ProductImage\DeleteRequest;
use Bitrix\Catalog\Infrastructure\Rest\Request\ProductImage\GetRequest;
use Bitrix\Catalog\Infrastructure\Rest\Request\ProductImage\ListRequest;
use Bitrix\Catalog\Internal\Service\Rest\ProductImageService;
use Bitrix\Catalog\UI\FileUploader\ProductController as ProductUploaderController;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\FileDtoProcessor;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

#[DtoType(ProductImageDto::class)]
class Image extends AbstractController
{
	public function listAction(ListRequest $request, ProductImageService $service): ListResponse
	{
		$productId = $this->resolveProductIdFromFilter($request->filter);

		$data = $this->requireResultData($service->list($productId, $request->pagination));
		$selectFields = $request->select?->getList() ?? [];

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($data['items'] ?? [], $selectFields),
		);
	}

	public function getAction(GetRequest $request, ProductImageService $service): GetResponse
	{
		$productId = $this->requirePositiveId($request->productId, 'productId');
		$id = $this->requirePositiveId($request->id);

		$data = $this->requireResultData($service->get($id, $productId));
		$selectFields = $request->select?->getList() ?? [];

		return new GetResponse(
			$this->getDtoMapper()->mapOne($data['item'] ?? [], $selectFields),
		);
	}

	public function addAction(AddRequest $request, ProductImageService $service): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		if (!$dto instanceof ProductImageDto)
		{
			throw new RequiredFieldInRequestException('fields');
		}

		$productId = $this->requirePositiveId($dto->productId, 'productId');

		// Rights first: the uploader gate checks another right and would leave an orphan file behind.
		$this->requireResultData($service->ensureAddAccess($productId));

		$fileId = $this->uploadFile($dto, $productId);

		$data = $this->requireResultData($service->add($productId, $dto->type, $fileId));

		return new GetResponse($this->getDtoMapper()->mapOne($data['item'] ?? []));
	}

	public function deleteAction(DeleteRequest $request, ProductImageService $service): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new InvalidFilterException('filter');
		}

		$productId = $this->requirePositiveId($request->productId, 'productId');
		$id = $this->requirePositiveId($request->id);

		$data = $this->requireResultData($service->delete($id, $productId));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}

	private function resolveProductIdFromFilter(?FilterStructure $filter): int
	{
		$simple = $filter?->getSimpleFilterConditions() ?? [];
		$productId = (int)($simple['productId'] ?? 0);
		if ($productId <= 0)
		{
			throw new RequestValidationException([
				new Error('productId must be a positive integer.', 'filter'),
			]);
		}

		return $productId;
	}

	/**
	 * The uploader controller carries the limits: images only, narrowed by the MORE_PHOTO property.
	 */
	private function uploadFile(ProductImageDto $dto, int $productId): int
	{
		$processor = new FileDtoProcessor(new ProductUploaderController(['productId' => $productId]));
		$processor->processDto($dto->file);

		$fileId = (int)($dto->file->id ?? 0);
		if ($fileId <= 0)
		{
			throw new RequestValidationException([new Error('Could not save image.', 'file')]);
		}

		return $fileId;
	}
}

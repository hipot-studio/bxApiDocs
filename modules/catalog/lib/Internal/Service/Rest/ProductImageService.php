<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductImageType;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\v2\IoC\ServiceContainer;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use CFile;
use CIBlock;
use CIBlockElement;
use CIBlockElementRights;

/**
 * Product images for public REST V3. An image is a projection: either an element field
 * (preview / detail picture) or a value of the MORE_PHOTO file property.
 */
final class ProductImageService
{
	use ApplicationErrors;

	private const FIELD_PREVIEW_PICTURE = 'PREVIEW_PICTURE';
	private const FIELD_DETAIL_PICTURE = 'DETAIL_PICTURE';

	private const IBLOCK_ELEMENT_READ = 'element_read';
	private const IBLOCK_ELEMENT_EDIT = 'element_edit';

	private const MORE_PHOTO_PROPERTY_CODE = 'MORE_PHOTO';
	private const PROPERTY_CACHE_TTL = 86400;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	/**
	 * The single point where the public image type meets the element field behind it.
	 * MORE_PHOTO has no element field: it lives in the property values.
	 */
	private static function resolveElementField(ProductImageType $type): ?string
	{
		return match ($type)
		{
			ProductImageType::PreviewPicture => self::FIELD_PREVIEW_PICTURE,
			ProductImageType::DetailPicture => self::FIELD_DETAIL_PICTURE,
			ProductImageType::MorePhoto => null,
		};
	}

	/**
	 * Write gate for the transport layer, which has to know the rights before it uploads a file.
	 */
	public function ensureAddAccess(int $productId): Result
	{
		$product = $this->loadProduct($productId);
		if ($product === null)
		{
			return ServiceResult::notFound($productId, 'Product was not found');
		}

		return $this->ensureModifyAccess($product);
	}

	public function list(int $productId, ?PaginationStructure $pagination): Result
	{
		$product = $this->loadProduct($productId);
		if ($product === null)
		{
			return ServiceResult::notFound($productId, 'Product was not found');
		}

		$access = $this->ensureReadAccess($product);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$images = $this->collectImages($product);

		$offset = $pagination?->getOffset() ?? 0;
		$limit = $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;

		return ServiceResult::success([
			'items' => array_slice($images, $offset, $limit),
		]);
	}

	public function get(int $id, int $productId): Result
	{
		$product = $this->loadProduct($productId);
		if ($product === null)
		{
			return ServiceResult::notFound($productId, 'Product was not found');
		}

		$access = $this->ensureReadAccess($product);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$image = $this->findImage($product, $id);
		if ($image === null)
		{
			return ServiceResult::notFound($id, 'productImage does not exist.');
		}

		return ServiceResult::success(['item' => $image]);
	}

	/**
	 * Binds an already uploaded file to the product. Uploading itself belongs to the transport layer.
	 */
	public function add(int $productId, ProductImageType $type, int $fileId): Result
	{
		$product = $this->loadProduct($productId);
		if ($product === null)
		{
			return ServiceResult::notFound($productId, 'Product was not found');
		}

		$access = $this->ensureModifyAccess($product);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$field = self::resolveElementField($type);
		if ($field === null)
		{
			return $this->addMorePhoto($product, $fileId);
		}

		return $this->addElementField($product, $type, $field, $fileId);
	}

	public function delete(int $id, int $productId): Result
	{
		$product = $this->loadProduct($productId);
		if ($product === null)
		{
			return ServiceResult::notFound($productId, 'Product was not found');
		}

		$access = $this->ensureModifyAccess($product);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$emptyFile = CIBlock::makeFileArray(null, true);

		// An empty list of MORE_PHOTO ids limits the first pass to the element fields,
		// so the property values are read only when the id is not one of them.
		$type = $this->resolveImageType($product, $id, []);
		$field = $type === null ? null : self::resolveElementField($type);
		if ($field !== null)
		{
			$write = $this->runInTransaction(
				fn(): ?string => $this->writeElementField((int)$product['ID'], [$field => $emptyFile]),
				'Internal error deleting product image. Try deleting again.',
			);
		}
		else
		{
			// One read of the property values answers both questions: is it a MORE_PHOTO
			// value of this product, and which value has to be cleared.
			$propertyValueId = $this->findMorePhotoValueId($this->loadMorePhotoValues($product), $id);
			if ($propertyValueId === null)
			{
				return ServiceResult::notFound($id, 'productImage does not exist.');
			}

			$write = $this->runInTransaction(
				fn(): ?string => $this->writeMorePhoto(
					(int)$product['ID'],
					(int)$product['IBLOCK_ID'],
					[$propertyValueId => $emptyFile],
				),
				'Internal error deleting product image. Try deleting again.',
			);
		}

		if (!$write->isSuccess())
		{
			return $write;
		}

		return ServiceResult::success(['result' => true]);
	}

	private function addMorePhoto(array $product, int $fileId): Result
	{
		$property = $this->ensureMorePhotoProperty((int)$product['IBLOCK_ID']);
		if (!$property->isSuccess())
		{
			return $property;
		}

		$write = $this->runInTransaction(
			fn(): ?string => $this->writeMorePhoto(
				(int)$product['ID'],
				(int)$product['IBLOCK_ID'],
				['n0' => $fileId],
			),
			'Internal error adding product image. Try adding again.',
		);
		if (!$write->isSuccess())
		{
			return $write;
		}

		// The uploaded file is bound by id, so the stored id is known upfront and the read
		// back only confirms that the property took the value.
		if (!in_array($fileId, $this->loadMorePhotoValues($product)['ids'], true))
		{
			return ServiceResult::validation([new Error('Empty image.')]);
		}

		return $this->buildImageResult($product, $fileId, ProductImageType::MorePhoto);
	}

	private function addElementField(array $product, ProductImageType $type, string $field, int $fileId): Result
	{
		$write = $this->runInTransaction(
			fn(): ?string => $this->writeElementField((int)$product['ID'], [$field => $this->toFileValue($fileId)]),
			'Internal error adding product image. Try adding again.',
		);
		if (!$write->isSuccess())
		{
			return $write;
		}

		$stored = $this->loadProduct((int)$product['ID']);
		$storedId = $stored === null ? 0 : (int)$stored[$field];
		if ($storedId <= 0)
		{
			return ServiceResult::validation([new Error('Empty image.')]);
		}

		$this->dropUploadedSource($fileId, $storedId);

		return $this->buildImageResult($stored, $storedId, $type);
	}

	//region Access

	private function ensureReadAccess(array $product): Result
	{
		$module = $this->accessSupport->ensureProductEntityRead();
		if (!$module->isSuccess())
		{
			return $module;
		}

		return $this->ensureElementRight($product, self::IBLOCK_ELEMENT_READ);
	}

	private function ensureModifyAccess(array $product): Result
	{
		$module = $this->accessSupport->ensureProductEdit();
		if (!$module->isSuccess())
		{
			return $module;
		}

		return $this->ensureElementRight($product, self::IBLOCK_ELEMENT_EDIT);
	}

	private function ensureElementRight(array $product, string $permission): Result
	{
		if (!CIBlockElementRights::UserHasRightTo((int)$product['IBLOCK_ID'], (int)$product['ID'], $permission))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	//endregion

	//region Reading

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadProduct(int $productId): ?array
	{
		if ($productId <= 0)
		{
			return null;
		}

		$product = ElementTable::getRow([
			'select' => ['ID', 'IBLOCK_ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'],
			'filter' => ['=ID' => $productId],
		]);

		// An element of a non catalog iblock is not a product: the catalog scope must not reach it.
		if ($product === null || !ServiceContainer::getIblockInfo((int)$product['IBLOCK_ID']))
		{
			return null;
		}

		return $product;
	}

	/**
	 * Domain order: preview, detail, then MORE_PHOTO values in property order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function collectImages(array $product): array
	{
		$morePhotoIds = $this->loadMorePhotoValues($product)['ids'];

		$fileIds = [];
		foreach ([self::FIELD_PREVIEW_PICTURE, self::FIELD_DETAIL_PICTURE] as $field)
		{
			$fileId = (int)($product[$field] ?? 0);
			if ($fileId > 0)
			{
				$fileIds[] = $fileId;
			}
		}
		foreach ($morePhotoIds as $fileId)
		{
			$fileIds[] = $fileId;
		}

		$fileIds = array_values(array_unique($fileIds));
		if ($fileIds === [])
		{
			return [];
		}

		$rows = [];
		$cursor = FileTable::getList(['filter' => ['=ID' => $fileIds]]);
		while ($row = $cursor->fetch())
		{
			$rows[(int)$row['ID']] = $row;
		}

		$images = [];
		foreach ($fileIds as $fileId)
		{
			$type = $this->resolveImageType($product, $fileId, $morePhotoIds);
			if ($type !== null && isset($rows[$fileId]))
			{
				$images[] = $this->buildImage($rows[$fileId], $type, (int)$product['ID']);
			}
		}

		return $images;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findImage(array $product, int $id): ?array
	{
		$type = $this->resolveImageType($product, $id);
		if ($type === null)
		{
			return null;
		}

		$row = FileTable::getRowById($id);
		if (!$row)
		{
			return null;
		}

		return $this->buildImage($row, $type, (int)$product['ID']);
	}

	/**
	 * The single source of truth for the image type: preview, then detail, then MORE_PHOTO.
	 *
	 * @param list<int>|null $morePhotoIds MORE_PHOTO file ids of the product, read on demand when null
	 */
	private function resolveImageType(array $product, int $id, ?array $morePhotoIds = null): ?ProductImageType
	{
		if ($id <= 0)
		{
			return null;
		}

		if ((int)($product[self::FIELD_PREVIEW_PICTURE] ?? 0) === $id)
		{
			return ProductImageType::PreviewPicture;
		}

		if ((int)($product[self::FIELD_DETAIL_PICTURE] ?? 0) === $id)
		{
			return ProductImageType::DetailPicture;
		}

		$morePhotoIds ??= $this->loadMorePhotoValues($product)['ids'];

		return in_array($id, $morePhotoIds, true) ? ProductImageType::MorePhoto : null;
	}

	private function buildImageResult(array $product, int $fileId, ProductImageType $type): Result
	{
		$row = FileTable::getRowById($fileId);
		if (!$row)
		{
			return ServiceResult::notFound($fileId, 'productImage does not exist.');
		}

		return ServiceResult::success(['item' => $this->buildImage($row, $type, (int)$product['ID'])]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildImage(array $file, ProductImageType $type, int $productId): array
	{
		$file['TYPE'] = $type->value;
		$file['PRODUCT_ID'] = $productId;

		return $file;
	}

	//endregion

	//region MORE_PHOTO property

	/**
	 * MORE_PHOTO values of the product: file ids in property order and the property value ids beside them.
	 *
	 * @return array{ids: list<int>, valueIds: list<int>}
	 */
	private function loadMorePhotoValues(array $product): array
	{
		$propertyId = $this->findMorePhotoPropertyId((int)$product['IBLOCK_ID']);
		if ($propertyId === null)
		{
			return ['ids' => [], 'valueIds' => []];
		}

		$row = CIBlockElement::getPropertyValues(
			$product['IBLOCK_ID'],
			[
				'ID' => $product['ID'],
				'IBLOCK_ID' => $product['IBLOCK_ID'],
			],
			true,
			['ID' => $propertyId],
		)->Fetch() ?: [];

		$values = array_values((array)($row[$propertyId] ?? []));
		$valueIds = array_values((array)($row['PROPERTY_VALUE_ID'][$propertyId] ?? []));

		$ids = [];
		$storedValueIds = [];
		foreach ($values as $index => $value)
		{
			$id = (int)$value;
			if ($id > 0)
			{
				$ids[] = $id;
				$storedValueIds[] = (int)($valueIds[$index] ?? 0);
			}
		}

		return ['ids' => $ids, 'valueIds' => $storedValueIds];
	}

	/**
	 * @param array{ids: list<int>, valueIds: list<int>} $morePhoto
	 */
	private function findMorePhotoValueId(array $morePhoto, int $fileId): ?int
	{
		$index = array_search($fileId, $morePhoto['ids'], true);
		if ($index === false)
		{
			return null;
		}

		$valueId = $morePhoto['valueIds'][$index] ?? 0;

		return $valueId > 0 ? $valueId : null;
	}

	private function findMorePhotoPropertyId(int $iblockId): ?int
	{
		$row = $this->readMorePhotoProperty($iblockId);
		if (
			$row === null
			|| $row['ACTIVE'] !== 'Y'
			|| $row['PROPERTY_TYPE'] !== PropertyTable::TYPE_FILE
		)
		{
			return null;
		}

		$id = (int)$row['ID'];

		return $id > 0 ? $id : null;
	}

	private function ensureMorePhotoProperty(int $iblockId): Result
	{
		$row = $this->readMorePhotoProperty($iblockId);

		if ($row === null)
		{
			return ServiceResult::validation([
				new Error('Image product property does not exists. Create MORE_PHOTO property'),
			]);
		}

		if ($row['ACTIVE'] !== 'Y')
		{
			return ServiceResult::validation([
				new Error('Image product property does not active. Activate MORE_PHOTO property'),
			]);
		}

		if ($row['PROPERTY_TYPE'] !== PropertyTable::TYPE_FILE)
		{
			return ServiceResult::validation([
				new Error('Image product property is of the wrong type'),
			]);
		}

		return ServiceResult::success();
	}

	/**
	 * The only read of the property row, so both callers share one ORM cache entry.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readMorePhotoProperty(int $iblockId): ?array
	{
		return PropertyTable::getRow([
			'select' => ['ID', 'ACTIVE', 'PROPERTY_TYPE'],
			'filter' => [
				'=IBLOCK_ID' => $iblockId,
				'=CODE' => self::MORE_PHOTO_PROPERTY_CODE,
			],
			'cache' => ['ttl' => self::PROPERTY_CACHE_TTL],
		]);
	}

	//endregion

	//region Writing

	/**
	 * @param callable(): ?string $write returns an error text or null on success
	 */
	private function runInTransaction(callable $write, string $sqlErrorText): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		// The transaction is closed even when a handler of a foreign module throws:
		// an open one would be committed implicitly by the next batch request.
		$finished = false;
		try
		{
			try
			{
				$error = $write();
			}
			catch (SqlQueryException)
			{
				$error = $sqlErrorText;
			}

			if ($error !== null && $error !== '')
			{
				return ServiceResult::validation([new Error($error)]);
			}

			$finished = true;
			$connection->commitTransaction();

			return ServiceResult::success();
		}
		finally
		{
			if (!$finished)
			{
				$connection->rollbackTransaction();
			}
		}
	}

	private function writeElementField(int $productId, array $fields): ?string
	{
		$element = new CIBlockElement();
		$element->update($productId, $fields);

		$error = (string)$element->getLastError();

		return $error === '' ? null : $error;
	}

	private function writeMorePhoto(int $productId, int $iblockId, array $values): ?string
	{
		CIBlockElement::SetPropertyValues(
			$productId,
			$iblockId,
			$values,
			self::MORE_PHOTO_PROPERTY_CODE,
		);

		return $this->getLastApplicationErrorText();
	}

	/**
	 * An element picture is bound by value, the same way the product card does it: CIBlockElement
	 * drops a plain id, and without allow_file_id an empty file comes back instead of the id.
	 * The platform stores a copy under a new id, so the uploaded file is dropped afterwards.
	 *
	 * @return array<string, mixed>
	 */
	private function toFileValue(int $fileId): array
	{
		return CIBlock::makeFileArray($fileId, false, null, ['allow_file_id' => true]);
	}

	/**
	 * The copy bound to the element replaces the uploaded file, which nothing references any more.
	 * The product property repository compensates the same way.
	 */
	private function dropUploadedSource(int $uploadedFileId, int $storedFileId): void
	{
		if ($uploadedFileId > 0 && $uploadedFileId !== $storedFileId)
		{
			CFile::Delete($uploadedFileId);
		}
	}

	//endregion
}

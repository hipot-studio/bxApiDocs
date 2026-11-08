<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

/**
 * Where a product image lives: an element field or a value of the MORE_PHOTO file property.
 */
enum ProductImageType: string
{
	case PreviewPicture = 'PREVIEW_PICTURE';
	case DetailPicture = 'DETAIL_PICTURE';
	case MorePhoto = 'MORE_PHOTO';
}

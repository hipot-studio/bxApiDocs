<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Request\ProductImage;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest as BaseDeleteRequest;

class DeleteRequest extends BaseDeleteRequest
{
	#[PositiveNumber]
	public int $productId;
}

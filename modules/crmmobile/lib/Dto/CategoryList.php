<?php

namespace Bitrix\CrmMobile\Dto;

use Bitrix\Mobile\Dto\Type;
use Bitrix\Mobile\Dto\Dto;

final class CategoryList extends Dto
{
	/** @var Category[]|null */
	public $categories = [];

	/** @var Restriction[]|null */
	public $restrictions;

	/** @var bool */
	public $canUserEditCategory;

	/**
	 * Whether a funnel may be created in the contour, and `null` where the payload states no
	 * capability at all. `null` is not a refusal: a client reading it falls back to
	 * `canUserEditCategory`, as DTO-01 requires.
	 *
	 * @var bool|null
	 */
	public $canCreateCategory;

	public function getCasts(): array
	{
		return [
			'categories' => Type::collection(Category::class),
			'restrictions' => Type::collection(Restriction::class),
			'canUserEditCategory' => Type::bool(),
			'canCreateCategory' => Type::bool()->nullable(),
		];
	}
}

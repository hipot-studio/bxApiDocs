<?php

namespace Bitrix\CrmMobile\Dto;

use Bitrix\Mobile\Dto\Type;
use Bitrix\Mobile\Dto\Dto;

final class Category extends Dto
{
	/** @var int|null */
	public $id;

	public int $categoryId;

	/** @var string|null */
	public $name;

	/** @var bool */
	public $editable;

	/**
	 * The configuration capabilities of this funnel, and `null` in each of them where the payload was
	 * built without the set. `null` is not a refusal: a client reading it falls back to `editable`,
	 * as DTO-01 requires. The set is therefore stated whole or not at all.
	 *
	 * @var bool|null
	 */
	public $canOpenSettings;

	/** @var bool|null */
	public $canManageFunnels;

	/** @var bool|null */
	public $canManageStages;

	/** @var bool|null */
	public $canManageTunnels;

	/** @var bool|null */
	public $canManageCategoryAccess;

	/** @var bool */
	public $isDefault;

	/** @var int|null */
	public $sort;

	/** @var bool */
	public $categoriesSupported = false;

	/** @var bool */
	public $categoriesEnabled = false;

	/** @var bool */
	public $stagesEnabled = false;

	/** @var bool */
	public $tunnelsEnabled = false;

	/** @var string|null */
	public $access;

	/** @var int|null */
	public $counter;

	/** @var Tunnel[]|null */
	public $tunnels = [];

	/** @var Stage[]|null */
	public $processStages = [];

	/** @var Stage[]|null */
	public $successStages = [];

	/** @var Stage[]|null */
	public $failedStages = [];

	/** @var DocumentField[]|null */
	public $documentFields;

	public function getCasts(): array
	{
		return [
			'id' => Type::int(),
			'name' => Type::string(),
			'editable' => Type::bool(),
			// stated whole or not at all, so `null` stays the absence of a decision and never a refusal
			'canOpenSettings' => Type::bool()->nullable(),
			'canManageFunnels' => Type::bool()->nullable(),
			'canManageStages' => Type::bool()->nullable(),
			'canManageTunnels' => Type::bool()->nullable(),
			'canManageCategoryAccess' => Type::bool()->nullable(),
			'isDefault' => Type::bool(),
			'sort' => Type::int(),
			'categoriesSupported' => Type::bool(),
			'categoriesEnabled' => Type::bool(),
			'stagesEnabled' => Type::bool(),
			'tunnelsEnabled' => Type::bool(),
			'counter' => Type::int(),
			'access' => Type::string()->nullable(),
			'tunnels' => Type::collection(Tunnel::class),
			'processStages' => Type::collection(Stage::class),
			'successStages' => Type::collection(Stage::class),
			'failedStages' => Type::collection(Stage::class),
			'documentFields' => Type::collection(DocumentField::class),
		];
	}
}

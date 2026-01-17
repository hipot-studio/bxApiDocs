<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation;

use Bitrix\Main\Provider\Params\SelectInterface;

class RelationTemplateSelect implements SelectInterface
{
	private const ALLOWED_FIELDS = [
		'id',
		'title',
		'responsible',
		'deadline',
	];

	public function __construct(
		public array $select = [],
	)
	{
	}

	public function prepareSelect(): array
	{
		return array_filter(
			$this->select,
			static fn (mixed $field): bool =>
				is_string($field) && in_array($field, self::ALLOWED_FIELDS, true),
		);
	}
}

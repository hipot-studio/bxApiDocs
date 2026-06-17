<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params;

enum SortDirection: string
{
	case Asc = 'asc';
	case Desc = 'desc';

	public static function getDefaultMapToRepositoryField(): array
	{
		return [
			self::Asc->value => 'ASC',
			self::Desc->value => 'DESC',
		];
	}
}
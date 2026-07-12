<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization;

use Bitrix\Main\Localization\Loc;

class ColumnMessage
{
	public const NAME = 'COLUMN_NAME';
	public const ACTIVITY_DATE = 'COLUMN_ACTIVITY_DATE';
	public const DATE_ACTIVITY = 'COLUMN_DATE_ACTIVITY';
	public const DATE_CREATE = 'COLUMN_DATE_CREATE';
	public const MEMBERS = 'COLUMN_MEMBERS';
	public const NUMBER_OF_MEMBERS = 'COLUMN_NUMBER_OF_MEMBERS';
	public const PRIVACY_TYPE = 'COLUMN_PRIVACY_TYPE';
	public const ROLE = 'COLUMN_ROLE';
	public const TAGS = 'COLUMN_TAGS';
	public const DATE_RELATION = 'COLUMN_DATE_RELATION';
	public const DATE_VIEW = 'COLUMN_DATE_VIEW';

	public static function get(string $code): string
	{
		return Loc::getMessage('SONET_V2_GRID_SHARED_' . $code) ?? '';
	}
}

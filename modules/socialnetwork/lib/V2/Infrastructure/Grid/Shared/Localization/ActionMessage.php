<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization;

use Bitrix\Main\Localization\Loc;

class ActionMessage
{
	public const VIEW = 'ACTION_VIEW';
	public const JOIN = 'ACTION_JOIN';
	public const DELETE_OUTGOING_REQUEST = 'ACTION_DELETE_OUTGOING_REQUEST';
	public const ADD_TO_FAVORITES = 'ACTION_ADD_TO_FAVORITES';
	public const REMOVE_FROM_FAVORITES = 'ACTION_REMOVE_FROM_FAVORITES';
	public const EDIT = 'ACTION_EDIT';
	public const LEAVE = 'ACTION_LEAVE';
	public const DELETE_INCOMING_REQUEST = 'ACTION_DELETE_INCOMING_REQUEST';
	public const ADD_TO_ARCHIVE = 'ACTION_ADD_TO_ARCHIVE';
	public const REMOVE_FROM_ARCHIVE = 'ACTION_REMOVE_FROM_ARCHIVE';
	public const DELETE = 'ACTION_DELETE';
	public const PANEL_CONFIRM = 'PANEL_CONFIRM';

	public static function get(string $code): string
	{
		return Loc::getMessage('SONET_V2_GRID_SHARED_' . $code) ?? '';
	}
}

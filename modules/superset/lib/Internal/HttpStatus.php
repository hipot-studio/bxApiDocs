<?php

namespace Bitrix\Superset\Internal;

final class HttpStatus
{
	public const OK = 200;
	public const CREATED = 201;
	public const ACCEPTED = 202;
	public const NO_CONTENT = 204;

	public const BAD_REQUEST = 400;
	public const UNAUTHORIZED = 401;
	public const FORBIDDEN = 403;
	public const NOT_FOUND = 404;
	public const CONFLICT = 409;
	public const UNPROCESSABLE_ENTITY = 422;
	public const UPGRADE_REQUIRED = 426;
	public const REGISTER_REQUIRED = 470;

	public const INTERNAL_SERVER_ERROR = 500;
	public const BAD_GATEWAY = 502;
	public const SERVICE_UNAVAILABLE = 503;
	public const UNKNOWN_ERROR = 520;
	public const SERVER_DOWN = 521;
	public const DEACTIVATED_INSTANCE = 555;
}
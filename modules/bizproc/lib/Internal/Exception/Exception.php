<?php

namespace Bitrix\Bizproc\Internal\Exception;

use Bitrix\Main\SystemException;

class Exception extends SystemException
{

	public const CODE_STORAGE_TYPE_CREATE = 1001;
	public const CODE_STORAGE_TYPE_UPDATE = 1002;
	public const CODE_STORAGE_TYPE_REMOVE = 1003;
	public const CODE_STORAGE_TYPE_NOT_FOUND = 1004;
	public const CODE_STORAGE_TYPE_DUPLICATE_ENTRY = 1063;
	public const CODE_STORAGE_ITEM_CREATE = 1005;
	public const CODE_STORAGE_ITEM_UPDATE = 1006;
	public const CODE_STORAGE_ITEM_REMOVE = 1007;
	public const CODE_STORAGE_ITEM_NOT_FOUND = 1008;

	public const CODE_STORAGE_FIELD_CREATE = 1009;

	public const CODE_STORAGE_FIELD_DUPLICATE_ENTRY = 1062;

	public const CODE_STORAGE_FIELD_UPDATE = 1010;
	public const CODE_STORAGE_FIELD_REMOVE = 1011;

	public const CODE_STORAGE_FIELD_NOT_FOUND = 1012;
}

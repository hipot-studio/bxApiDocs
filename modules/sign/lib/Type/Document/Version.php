<?php

namespace Bitrix\Sign\Type\Document;

abstract class Version
{
	// v3: result-file callback changed to file-ready callback
	// v2: new api
	public const V3 = 3;
	public const V2 = 2;
	public const V1 = 1;
	public const CURRENT = self::V3;
}

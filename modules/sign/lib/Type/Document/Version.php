<?php

namespace Bitrix\Sign\Type\Document;

/**
 * - v4: MemberPrintVersionFile callback changed to MemberPrintVersionFileReady callback
 * - v3: MemberResultFile callback changed to MemberResultFileReady callback
 * - v2: new api
 */
abstract class Version
{
	public const V4 = 4; // MemberPrintVersionFileReady
	public const V3 = 3; // MemberResultFileReady
	public const V2 = 2;
	public const V1 = 1;
	public const CURRENT = self::V4;
}

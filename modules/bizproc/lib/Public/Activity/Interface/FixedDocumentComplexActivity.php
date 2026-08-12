<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

interface FixedDocumentComplexActivity
{
	public static function getDocumentTypeForNodeAction(): array;
}

<?php

namespace Bitrix\Bizproc\Public\Service\Template;

final class MakeTemplatePackageDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $section,
		public readonly string $code,
		public readonly ?string $outputDir = null,
	){}
}

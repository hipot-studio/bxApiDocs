<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Document\DocumentService;

class DocumentNameAndUrlDto
{
	public function __construct(
		public readonly string $name,
		public readonly string $url
	) {}
}

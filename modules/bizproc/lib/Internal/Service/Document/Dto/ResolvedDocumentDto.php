<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Document\Dto;

use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexId;
use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;

readonly class ResolvedDocumentDto
{
	public function __construct(
		public DocumentComplexType $complexDocumentType,
		public ?DocumentComplexId $complexDocumentId = null,
	)
	{}
}

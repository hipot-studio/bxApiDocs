<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent;

abstract class DocumentTypeFilter
{
	abstract public function loadFromArray(array $parameters): void;
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

interface TargetDocumentResolver
{
	public function supports(\CBPActivity $activity): bool;

	public function resolveDocumentId(\CBPActivity $activity): array;

	public function resolveDocumentType(array $documentId, array $fallbackDocumentType): array;
}

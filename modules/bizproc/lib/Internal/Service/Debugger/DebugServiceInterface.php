<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;

interface DebugServiceInterface
{
	public function enableForTemplate(int $userId, int $templateId): Debug;

	public function enableForDocument(int $userId, int $templateId, array $documentId): Debug;

	public function disable(int $userId, int $templateId, ?array $documentId = null): void;

	public function disableAll(int $userId): void;

	public function isEnabled(int $userId, int $templateId, ?array $documentId = null): bool;

	public function getDebug(int $userId, int $templateId, ?array $documentId = null, ?bool $isEnabled = null): ?Debug;

	public function getActiveDebugForUser(int $userId): ?Debug;
}

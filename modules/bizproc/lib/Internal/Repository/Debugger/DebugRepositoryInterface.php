<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Entity\Debugger\DocumentId;

interface DebugRepositoryInterface
{
	public function find(int $userId, int $templateId, ?DocumentId $documentId = null, ?bool $isEnabled = null): ?Debug;

	public function findByUserId(int $userId): ?Debug;

	public function first(int $id): ?Debug;

	public function save(Debug $debug): void;

	public function disable(int $userId, int $templateId, ?DocumentId $documentId = null): void;

	public function disableAllForUser(int $userId): void;

	public function delete(int $id): void;

	public function exists(int $userId, int $templateId, ?DocumentId $documentId = null): bool;
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugSessionCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Main\Provider\Params\FilterInterface;

interface DebugSessionRepositoryInterface
{
	public function save(DebugSession $debugSessionEntity): void;

	public function first(int $id, bool $withTraces = true): ?DebugSession;

	public function delete(int $id): void;

	public function exists(int $id): bool;

	public function findByUserId(int $userId, bool $withTraces = false): DebugSessionCollection;

	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): DebugSessionCollection;

	public function getByWorkflowId(string $workflowId): DebugSessionCollection;

	public function deleteOlderThan(int $days): int;

	public function deleteByUserId(int $userId): int;
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Provider\Params\FilterInterface;

interface DebugTraceRepositoryInterface
{
	public function save(DebugTrace $debugTraceEntity): void;

	public function saveCollection(DebugTraceCollection $debugTraceCollection): void;

	public function first(int $id): ?DebugTrace;

	public function findByDebugSessionId(
		int              $debugSessionId,
		?int             $limit = null,
		?int             $offset = null,
		?FilterInterface $filter = null,
		?array           $sort = null,
		?array           $select = null,
	): DebugTraceCollection;

	public function getList(
		?int             $limit = null,
		?int             $offset = null,
		?FilterInterface $filter = null,
		?array           $sort = null,
		?array           $select = null,
	): DebugTraceCollection;

	public function delete(int $id): void;

	public function deleteByDebugSessionId(int $debugSessionId): void;
}

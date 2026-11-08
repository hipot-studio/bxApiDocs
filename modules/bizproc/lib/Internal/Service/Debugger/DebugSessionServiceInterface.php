<?php

namespace Bitrix\Bizproc\Internal\Service\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Bizproc\Internal\Entity\Debugger\DocumentId;
use Bitrix\Bizproc\Internal\Entity\Debugger\Logs;
use Bitrix\Bizproc\Internal\Entity\Debugger\Metrics;
use Bitrix\Bizproc\Internal\Entity\Debugger\TraceType;

interface DebugSessionServiceInterface
{
	public function getDebugSession(): DebugSession;

	public function createSession(Debug $debug, DocumentId $documentId): DebugSession;

	/**
	 * Makes an already existing session the current one of the hit; null leaves the hit without one.
	 */
	public function restoreSession(?DebugSession $session): void;

	public function findDebugSession(): ?DebugSession;

	public function addTrace(
		TraceType $type,
		string $key,
		string $message,
		array|null $context = null,
		array|null $data = null,
	): DebugTrace;

	public function finish(): void;

	public function flush(): void;

	public function getLogs(): Logs;

	public function getMetrics(): Metrics;
}

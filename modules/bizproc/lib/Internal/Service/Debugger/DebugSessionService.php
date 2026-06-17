<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Bizproc\Internal\Entity\Debugger\DocumentId;
use Bitrix\Bizproc\Internal\Entity\Debugger\Logs;
use Bitrix\Bizproc\Internal\Entity\Debugger\Metrics;
use Bitrix\Bizproc\Internal\Entity\Debugger\TraceType;
use Bitrix\Bizproc\Internal\Exception\Debugger\DebuggerException;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugTraceRepositoryInterface;
use Bitrix\Main\Application;
use Throwable;


final class DebugSessionService implements DebugSessionServiceInterface
{
	private null|DebugSession $debugSession = null;

	private DebugSessionRepositoryInterface $debugSessionRepository;
	private DebugTraceRepositoryInterface $debugTraceRepository;

	public function __construct(
		DebugSessionRepositoryInterface $debugSessionRepository,
		DebugTraceRepositoryInterface $debugTraceRepository,
	)
	{
		$this->debugSessionRepository = $debugSessionRepository;
		$this->debugTraceRepository = $debugTraceRepository;
	}

	public function createSession(Debug $debug, DocumentId $documentId): DebugSession
	{
		$session = DebugSession::create($documentId, $debug->getId(), $debug->getUserId(), $debug->getTemplateId());
		$this->debugSession = $session;

		return $session;
	}

	/**
	 * @throws DebuggerException
	 */
	public function finish(): void
	{
		$this->getDebugSession()->end();
	}

	/**
	 * @throws DebuggerException
	 */
	public function getDebugSession(): DebugSession
	{
		if (!$this->debugSession) {
			throw DebuggerException::currentSessionNotSet();
		}

		return $this->debugSession;
	}

	/**
	 * @throws Throwable
	 */
	public function flush(): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->debugSessionRepository->save($this->debugSession);
			$this->debugTraceRepository->saveCollection($this->debugSession->getTraces());

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	/**
	 * @throws DebuggerException
	 */
	public function addTrace(
		TraceType $type,
		string $key,
		string $message,
		array|null $context = null,
		array|null $data = null,
	): DebugTrace
	{
		return $this->getDebugSession()->addTrace($type, $key, $message, $context, $data);
	}

	/**
	 * @throws DebuggerException
	 */
	public function getLogs(): Logs
	{
		return $this->getDebugSession()->getLogs();
	}

	/**
	 * @throws DebuggerException
	 */
	public function getMetrics(): Metrics
	{
		return $this->getDebugSession()->getMetrics();
	}
}

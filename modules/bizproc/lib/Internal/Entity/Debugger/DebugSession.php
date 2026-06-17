<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Entity\EntityInterface;

final class DebugSession implements EntityInterface
{
	private ?int $id = null;
	private ?int $userId = null;
	private ?int $debugId = null;
	private ?DocumentId $documentId = null;
	private ?string $workflowId = null;
	private ?int $templateId = null;
	private ?Timestamp $startTime = null;
	private ?Timestamp $endTime = null;
	private array $metadata = [];
	private Logs $logs;
	private Metrics $metrics;
	private DebugTraceCollection $traces;

	private function __construct()
	{
		$this->logs = new Logs();
		$this->metrics = new Metrics();
		$this->traces = new DebugTraceCollection();
	}

	public static function mapFromArray(array $props): self
	{
		$debug = new self();

		if (isset($props['id']))
		{
			$debug->setId((int)$props['id']);
		}

		if (isset($props['user_id']))
		{
			$debug->userId = (int)$props['user_id'];
		}

		if (isset($props['debug_id']))
		{
			$debug->debugId = (int)$props['debug_id'];
		}

		if (isset($props['module_id'], $props['entity'], $props['document_id']))
		{
			$debug->documentId = new DocumentId(
				(string)$props['module_id'],
				(string)$props['entity'],
				(string)$props['document_id'],
			);
		}

		if (isset($props['workflow_id']))
		{
			$debug->workflowId = (string)$props['workflow_id'];
		}

		if (isset($props['template_id']))
		{
			$debug->templateId = (int)$props['template_id'];
		}

		if (isset($props['start_time']))
		{
			$debug->startTime = Timestamp::tryFromFloat((float)$props['start_time']);
		}

		if (isset($props['end_time']))
		{
			$debug->endTime = Timestamp::tryFromFloat((float)$props['end_time']);
		}

		if (isset($props['metadata']) && is_array($props['metadata']))
		{
			$debug->metadata = $props['metadata'];
		}

		return $debug;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		foreach ($this->traces as $trace)
		{
			$trace->setDebugSessionId($id);
		}

		return $this;
	}

	public function addTrace(
		TraceType $type,
		string $key,
		string $message,
		array|null $context = null,
		array|null $data = null,
	): DebugTrace
	{
		$trace = DebugTrace::create($type, $key, $message)
			->setContext($context)
			->setData($data)
		;

		if ($this->id !== null)
		{
			$trace->setDebugSessionId($this->id);
		}

		$this->traces->add($trace);

		return $trace;
	}

	public static function create(DocumentID $documentId, int $debugId, int $userId, int $templateId): self
	{
		$debug = new self();
		$debug->debugId = $debugId;
		$debug->userId = $userId;
		$debug->documentId = $documentId;
		$debug->templateId = $templateId;
		$debug->startTime = Timestamp::now();

		return $debug;
	}

	public function putTraces(DebugTraceCollection $traceCollection): self
	{
		foreach ($traceCollection as $trace)
		{
			$this->putTrace($trace);
		}

		return $this;
	}

	public function putTrace(DebugTrace $trace): self
	{
		$this->traces[] = $trace;

		return $this;
	}

	public function addMetrics(string $key, string $value): void
	{
		$this->metrics[] = [
			'key' => $key,
			'value' => $value,
			'timestamp' => Timestamp::now()->getValue(),
		];
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function getDebugId(): ?int
	{
		return $this->debugId;
	}

	public function setDebugId(?int $debugId): self
	{
		$this->debugId = $debugId;

		return $this;
	}

	public function getDocumentId(): ?DocumentId
	{
		return $this->documentId;
	}

	public function getWorkflowId(): ?string
	{
		return $this->workflowId;
	}

	public function setWorkflowId(string $value): self
	{
		$this->workflowId = $value;

		return $this;
	}

	public function getTemplateId(): ?int
	{
		return $this->templateId;
	}

	public function getStartTime(): ?Timestamp
	{
		return $this->startTime;
	}

	public function getEndTime(): ?Timestamp
	{
		return $this->endTime;
	}

	public function getMetadata(): array
	{
		return $this->metadata;
	}

	public function getLogs(): Logs
	{
		return $this->logs;
	}

	public function getMetrics(): Metrics
	{
		return $this->metrics;
	}

	public function end(): self
	{
		if (!$this->isEnded())
		{
			$this->endTime = Timestamp::now();
		}

		return $this;
	}

	public function isEnded(): bool
	{
		return $this->endTime !== null;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'debug_id' => $this->debugId,
			'module_id' => $this->documentId?->moduleId,
			'entity' => $this->documentId?->entity,
			'document_id' => $this->documentId?->documentId,
			'workflow_id' => $this->workflowId,
			'template_id' => $this->templateId,
			'start_time' => $this->startTime?->getValue(),
			'end_time' => $this->endTime?->getValue(),
			'duration' => $this->getDuration(),
			'metadata' => $this->metadata,
			'logs' => $this->logs->toArray(),
			'metrics' => $this->metrics,
			'traces' => $this->traces->toArray(),
		];
	}

	public function getDuration(): float
	{
		$startValue = $this->startTime?->getValue() ?? 0;

		if ($this->endTime === null)
		{
			return microtime(true) - $startValue;
		}

		return $this->endTime->getValue() - $startValue;
	}

	public function getTraces(): DebugTraceCollection
	{
		return $this->traces;
	}
}

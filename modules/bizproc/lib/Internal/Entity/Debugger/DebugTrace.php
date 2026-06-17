<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

final class DebugTrace implements EntityInterface
{
	private ?int $id = null;
	private ?int $debugSessionId = null;
	private ?string $key = null;
	private ?TraceType $type = null;
	private ?string $message = null;
	private ?array $data = null;
	private ?array $context = null;
	private ?Timestamp $timestamp = null;

	public static function create(TraceType $type, string $key, string $message): self
	{
		$trace = new self();
		$trace->key = $key;
		$trace->type = $type;
		$trace->message = $message;
		$trace->timestamp = Timestamp::now();

		return $trace;
	}

	public static function mapFromArray(array $props): self
	{
		$trace = new self();

		if (isset($props['id']) && is_numeric($props['id']))
		{
			$trace->id = (int)$props['id'];
		}

		if (isset($props['debug_session_id']) && is_numeric($props['debug_session_id']))
		{
			$trace->debugSessionId = (int)$props['debug_session_id'];
		}

		if (isset($props['key']))
		{
			$trace->key = (string)$props['key'];
		}

		if (isset($props['type']) && is_string($props['type']))
		{
			$trace->type = TraceType::tryFrom($props['type']);
		}

		if (isset($props['message']))
		{
			$trace->message = (string)$props['message'];
		}

		if (isset($props['data']) && is_array($props['data']))
		{
			$trace->data = $props['data'];
		}

		if (isset($props['context']) && is_array($props['context']))
		{
			$trace->context = $props['context'];
		}

		if (isset($props['timestamp']) && is_numeric($props['timestamp']))
		{
			$trace->timestamp = Timestamp::tryFromFloat((float)$props['timestamp']);
		}

		return $trace;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function getKey(): ?string
	{
		return $this->key;
	}

	public function setKey(?string $key): self
	{
		$this->key = $key;

		return $this;
	}

	public function getDebugSessionId(): ?int
	{
		return $this->debugSessionId;
	}

	public function setDebugSessionId(int $debugSessionId): self
	{
		$this->debugSessionId = $debugSessionId;

		return $this;
	}

	public function getType(): ?TraceType
	{
		return $this->type;
	}

	public function getMessage(): ?string
	{
		return $this->message;
	}

	public function setMessage(?string $message): self
	{
		$this->message = $message;

		return $this;
	}

	public function getData(): ?array
	{
		return $this->data;
	}

	public function setData(?array $data): self
	{
		$this->data = $data;

		return $this;
	}

	public function getContext(): ?array
	{
		return $this->context;
	}

	public function setContext(?array $context): self
	{
		$this->context = $context;

		return $this;
	}

	public function getTimestamp(): ?Timestamp
	{
		return $this->timestamp;
	}

	public function setTimestamp(Timestamp $timestamp): self
	{
		$this->timestamp = $timestamp;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'debug_session_id' => $this->debugSessionId,
			'key' => $this->key,
			'type' => $this->type,
			'message' => $this->message,
			'data' => $this->data,
			'context' => $this->context,
			'timestamp' => $this->timestamp?->getValue(),
		];
	}
}

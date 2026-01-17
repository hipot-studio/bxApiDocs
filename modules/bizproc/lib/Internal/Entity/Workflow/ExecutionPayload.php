<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Workflow;

class ExecutionPayload
{
	private ?string $parentName = null;
	private int $parentPort = 0;
	private int $inputPort = 0;

	public function getParentName(): ?string
	{
		return $this->parentName;
	}

	public function setParentName(string $parentName): static
	{
		$this->parentName = $parentName;

		return $this;
	}

	public function getParentPort(): int
	{
		return $this->parentPort;
	}

	public function setParentPort(int $parentPort): static
	{
		$this->parentPort = $parentPort;

		return $this;
	}

	public function getParentLink(): string
	{
		$name = $this->getParentName();
		if ($name === null)
		{
			throw new \CBPArgumentNullException('parentName');
		}

		return implode(':o', [$name, $this->getParentPort()]);
	}

	public function getInputPort(): int
	{
		return $this->inputPort;
	}

	public function setInputPort(int $inputPort): static
	{
		$this->inputPort = $inputPort;

		return $this;
	}
}

<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

abstract class AbstractProperty
{
	protected bool $isRequired = false;
	protected bool $isNullable = false;

	public function __construct(
		protected string $id,
		protected string $description,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setIsRequired(bool $isRequired): self
	{
		$this->isRequired = $isRequired;

		return $this;
	}

	public function isRequired(): bool
	{
		return $this->isRequired;
	}

	public function setIsNullable(bool $isNullable): self
	{
		$this->isNullable = $isNullable;

		return $this;
	}

	public function isNullable(): bool
	{
		return $this->isNullable;
	}

	abstract public function getType(): string;

	public function toArray(): array
	{
		return [
			'description' => $this->description,
			'type' => $this->isNullable ? [$this->getType(), 'null'] : $this->getType(),
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties;

abstract class AbstractProperty
{
	use Validation\ConstValidationTrait;
	use Validation\EnumValidationTrait;

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

	public function setIsRequired(bool $isRequired): static
	{
		$this->isRequired = $isRequired;

		return $this;
	}

	public function isRequired(): bool
	{
		return $this->isRequired;
	}

	public function setIsNullable(bool $isNullable): static
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
			...$this->getBaseSchema(),
			...$this->getCommonValidationSchema(),
		];
	}

	protected function getBaseSchema(): array
	{
		return [
			'description' => $this->description,
			'type' => $this->isNullable ? [$this->getType(), 'null'] : $this->getType(),
		];
	}

	protected function getCommonValidationSchema(): array
	{
		return [
			...$this->getEnumValidationSchema(),
			...$this->getConstValidationSchema(),
		];
	}
}

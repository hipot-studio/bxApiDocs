<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

abstract class FormField implements \JsonSerializable
{
	protected string $id;
	protected ?string $label;
	protected bool $disabled;

	protected function __construct(array $fieldData)
	{
		$this->id = (string)($fieldData['id'] ?? '');
		$this->label = $fieldData['label'] ?? null;
		$this->disabled = $fieldData['disabled'] ?? false;
	}

	public static function create(array $fieldData): ?self
	{
		return match ($fieldData['type'] ?? '')
		{
			Type::SingleLineText->value => new SingleLineTextField($fieldData),
			Type::MultilineText->value => new MultilineTextField($fieldData),
			Type::Select->value => new SelectField($fieldData),
			Type::Radio->value => new RadioField($fieldData),
			Type::Checkbox->value => new CheckboxField($fieldData),
			default => null,
		};
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function setId(string $id): void
	{
		$this->id = $id;
	}

	public function getLabel(): ?string
	{
		return $this->label;
	}

	public function isDisabled(): bool
	{
		return $this->disabled;
	}

	public abstract function jsonSerialize(): array;

	public abstract function toArray(): array;

	public abstract function getType(): string;
}

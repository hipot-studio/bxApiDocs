<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Button;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\ButtonCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\FormField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\FormFieldCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Main\Security\Random;

class FormBlock extends AbstractBlock
{
	protected FormFieldCollection $elements;
	protected ButtonCollection $buttons;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->elements = FormFieldCollection::create($blockData[Field::Elements->value] ?? []);
		$this->buttons = ButtonCollection::create($blockData[Field::Buttons->value] ?? []);
		$this->uniqueIds();
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Form;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Elements->value => $this->elements->jsonSerialize(),
			Field::Buttons->value => $this->buttons->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Elements->value => $this->elements->toArray(),
			Field::Buttons->value => $this->buttons->toArray(),
		];
	}

	public function getPayloadText(): ?string
	{
		$labels = [];
		foreach ($this->elements as $element)
		{
			/** @var FormField $element */
			$label = $element->getLabel();
			if (!empty($label))
			{
				$labels[] = $label;
			}
		}

		if (empty($labels))
		{
			return null;
		}

		return implode(PHP_EOL, $labels);
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Elements->value,
			Field::Buttons->value,
		];
	}

	public function getElements(): FormFieldCollection
	{
		return $this->elements;
	}

	public function getButtonById(string $id): ?Button
	{
		foreach ($this->buttons as $button)
		{
			if ($button->getId() === $id)
			{
				return $button;
			}
		}

		return null;
	}

	/**
	 * Makes element (elements) and button (buttons) ids unique against a SINGLE shared pool — an id
	 * must be unique within the whole message, not just within its own collection. ButtonCollection::create()
	 * already deduplicates button ids among themselves but is unaware of form field ids — this pass
	 * additionally resolves collisions between elements and buttons.
	 */
	private function uniqueIds(): void
	{
		$seenIds = [];

		foreach ($this->elements as $element)
		{
			/** @var FormField $element */
			$id = $element->getId();
			if ($id === '' || isset($seenIds[$id]))
			{
				$id = $this->generateUniqueId($seenIds);
				$element->setId($id);
			}
			$seenIds[$id] = true;
		}

		foreach ($this->buttons as $button)
		{
			$id = $button->getId();
			if ($id === '' || isset($seenIds[$id]))
			{
				$id = $this->generateUniqueId($seenIds);
				$button->setId($id);
			}
			$seenIds[$id] = true;
		}
	}

	private function generateUniqueId(array $seenIds): string
	{
		do
		{
			$id = Random::getString(13, true);
		}
		while (isset($seenIds[$id]));

		return $id;
	}
}

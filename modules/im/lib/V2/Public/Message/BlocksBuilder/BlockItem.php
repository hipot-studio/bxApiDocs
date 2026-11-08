<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Color;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\SpaceDividerSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\TitleSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Fold;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\FormFields;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\Icon;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\OrderedListElements;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\UnorderedListElements;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Sources;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\TableRows;

class BlockItem
{
	private function __construct(
		public readonly array $blockData
	)
	{}

	public static function createTextBlock(
		string $text,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Text->value,
			Field::Text->value => $text,
			Field::Sources->value => $sources?->jsonSerialize(),
		]);
	}

	public static function createTitleBlock(
		string $text,
		TitleSize $size = TitleSize::Small,
		Color $color = Color::Base,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Title->value,
			Field::Text->value => $text,
			Field::Size->value => $size->value,
			Field::Color->value => $color->value,
		]);
	}

	public static function createOrderedListBlock(
		OrderedListElements $elements,
		Color $color = Color::Base,
		?Fold $fold = null,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::OrderedList->value,
			Field::Elements->value => $elements->jsonSerialize(),
			Field::Fold->value => $fold?->jsonSerialize(),
			Field::Color->value => $color->value,
			Field::Sources->value => $sources?->jsonSerialize(),
		]);
	}

	public static function createUnorderedListBlock(
		UnorderedListElements $elements,
		Color $color = Color::Base,
		?Icon $icon = null,
		?Fold $fold = null,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::UnorderedList->value,
			Field::Icon->value => $icon?->jsonSerialize(),
			Field::Elements->value => $elements->jsonSerialize(),
			Field::Fold->value => $fold?->jsonSerialize(),
			Field::Color->value => $color->value,
			Field::Sources->value => $sources?->jsonSerialize(),
		]);
	}

	public static function createTableBlock(
		TableRows $rows,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Table->value,
			Field::Rows->value => $rows->jsonSerialize(),
		]);
	}

	public static function createSpaceDividerBlock(
		SpaceDividerSize $size = SpaceDividerSize::Small,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::SpaceDivider->value,
			Field::Size->value => $size->value,
		]);
	}

	public static function createLineDividerBlock(?string $id = null): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::LineDivider->value,
		]);
	}

	public static function createMapBlock(
		string $imageUrl,
		?string $text = null,
		?string $status = null,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Map->value,
			Field::ImageUrl->value => $imageUrl,
			Field::Text->value => $text,
			Field::Status->value => $status,
		]);
	}

	public static function createAiAssistantSearchBlock(
		string $title,
		string $text,
		?string $id = null,
		?int $progressValue = null,
		?Color $progressColor = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::AiAssistantSearch->value,
			Field::Title->value => $title,
			Field::Text->value => $text,
			Field::Progress->value => self::buildProgress($progressValue, $progressColor),
		]);
	}

	public static function createCardBlock(
		string $title,
		?string $imageUrl = null,
		?string $text = null,
		?Buttons $buttons = null,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Card->value,
			Field::Title->value => $title,
			Field::Text->value => $text,
			Field::ImageUrl->value => $imageUrl,
			Field::Buttons->value => $buttons?->jsonSerialize(),
		]);
	}

	public static function createGalleryBlock(
		array $fileIds,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Gallery->value,
			Field::FileIds->value => $fileIds,
		]);
	}

	public static function createFileBlock(
		array $fileIds,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::File->value,
			Field::FileIds->value => $fileIds,
		]);
	}

	public static function createAudioBlock(
		int $fileId,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Audio->value,
			Field::FileId->value => $fileId,
		]);
	}

	public static function createFormBlock(
		FormFields $fields,
		Buttons $buttons,
		?string $id = null
	): self
	{
		return new self([
			Field::Id->value => $id,
			Field::Type->value => BlockType::Form->value,
			Field::Elements->value => $fields->jsonSerialize(),
			Field::Buttons->value => $buttons->jsonSerialize(),
		]);
	}

	/**
	 * A service block is shown by clients, but its text stays out of the message payload text.
	 * The flag is available for every block type, so it is applied to a ready block item.
	 */
	public function asService(): self
	{
		return new self(array_merge($this->blockData, [Field::IsService->value => true]));
	}

	private static function buildProgress(?int $value, ?Color $color): ?array
	{
		if ($value === null)
		{
			return null;
		}

		return [
			'value' => $value,
			'colorToken' => $color?->value,
		];
	}
}

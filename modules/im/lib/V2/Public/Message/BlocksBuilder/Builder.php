<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderResult;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Color;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\SpaceDividerSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\TitleSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Fold;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\Icon;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\OrderedListElements;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Sources;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\TableRows;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\UnorderedListElements;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Main\DI\ServiceLocator;

class Builder
{
	protected array $blocks = [];
	protected array $config = [];

	public function addTextBlock(
		string $text,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::Text->value,
			Field::Text->value => $text,
			Field::Sources->value => $sources?->jsonSerialize(),
		];

		return $this;
	}

	public function addTitleBlock(
		string $text,
		TitleSize $size = TitleSize::Small,
		Color $color = Color::Base,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::Title->value,
			Field::Text->value => $text,
			Field::Size->value => $size->value,
			Field::Color->value => $color->value,
		];

		return $this;
	}

	public function addOrderedListBlock(
		OrderedListElements $elements,
		Color $color = Color::Base,
		?Fold $fold = null,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::OrderedList->value,
			Field::Elements->value => $elements->jsonSerialize(),
			Field::Fold->value => $fold?->jsonSerialize(),
			Field::Color->value => $color->value,
			Field::Sources->value => $sources?->jsonSerialize(),
		];

		return $this;
	}

	public function addUnorderedListBlock(
		UnorderedListElements $elements,
		Color $color = Color::Base,
		?Icon $icon = null,
		?Fold $fold = null,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::UnorderedList->value,
			Field::Icon->value => $icon?->jsonSerialize(),
			Field::Elements->value => $elements->jsonSerialize(),
			Field::Fold->value => $fold?->jsonSerialize(),
			Field::Color->value => $color->value,
			Field::Sources->value => $sources?->jsonSerialize(),
		];

		return $this;
	}

	public function addTableBlock(
		TableRows $rows,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::Table->value,
			Field::Rows->value => $rows->jsonSerialize(),
		];

		return $this;
	}

	public function addSpaceDividerBlock(
		SpaceDividerSize $size = SpaceDividerSize::Small,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::SpaceDivider->value,
			Field::Size->value => $size->value,
		];

		return $this;
	}

	public function addLineDividerBlock(?string $id = null): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::LineDivider->value,
		];

		return $this;
	}

	public function addMapBlock(
		string $imageUrl,
		?string $text = null,
		?string $status = null,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::Map->value,
			Field::ImageUrl->value => $imageUrl,
			Field::Text->value => $text,
			Field::Status->value => $status,
		];

		return $this;
	}

	public function addAiAssistantSearchBlock(
		string $title,
		string $text,
		?string $id = null
	): self
	{
		$this->blocks[] = [
			Field::Id->value => $id,
			Field::Type->value => BlockType::AiAssistantSearch->value,
			Field::Title->value => $title,
			Field::Text->value => $text,
		];

		return $this;
	}

	public function build(): BuilderResult
	{
		$builderData = $this->config;
		$builderData['blocks'] = $this->blocks;

		return ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData);
	}
}

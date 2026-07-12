<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderResult;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Config\Background;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\Color;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\SpaceDividerSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\TitleSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Fold;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\Icon;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\OrderedListElements;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Sources;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\TableRows;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\List\UnorderedListElements;
use Bitrix\Main\DI\ServiceLocator;

class Builder
{
	protected Chat $chat;
	protected array $blocks = [];
	protected array $config = [];

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public function setBackground(?Background $background): self
	{
		$this->config[Field::Background->value] = $background?->value;

		return $this;
	}

	public function addTextBlock(
		string $text,
		?Sources $sources = null,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createTextBlock($text, $sources, $id)->blockData;

		return $this;
	}

	public function addTitleBlock(
		string $text,
		TitleSize $size = TitleSize::Small,
		Color $color = Color::Base,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createTitleBlock($text, $size, $color, $id)->blockData;

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
		$this->blocks[] = BlockItem::createOrderedListBlock($elements,$color, $fold, $sources, $id)->blockData;

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
		$this->blocks[] = BlockItem::createUnorderedListBlock($elements, $color, $icon, $fold, $sources, $id)->blockData;

		return $this;
	}

	public function addTableBlock(
		TableRows $rows,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createTableBlock($rows, $id)->blockData;

		return $this;
	}

	public function addSpaceDividerBlock(
		SpaceDividerSize $size = SpaceDividerSize::Small,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createSpaceDividerBlock($size, $id)->blockData;

		return $this;
	}

	public function addLineDividerBlock(?string $id = null): self
	{
		$this->blocks[] = BlockItem::createLineDividerBlock($id)->blockData;
		return $this;
	}

	public function addMapBlock(
		string $imageUrl,
		?string $text = null,
		?string $status = null,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createMapBlock($imageUrl, $text, $status, $id)->blockData;

		return $this;
	}

	public function addAiAssistantSearchBlock(
		string $title,
		string $text,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createAiAssistantSearchBlock($title, $text, $id)->blockData;

		return $this;
	}

	public function addCardBlock(
		string $title,
		?string $imageUrl = null,
		?string $text = null,
		?Buttons $buttons = null,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createCardBlock($title, $imageUrl, $text, $buttons, $id)->blockData;

		return $this;
	}

	public function addGalleryBlock(
		array $fileIds,
		?string $id = null
	): self
	{
		$this->blocks[] = BlockItem::createGalleryBlock($fileIds, $id)->blockData;

		return $this;
	}

	public function build(): BuilderResult
	{
		$builderData = [
			'config' => $this->config,
			'elements' => $this->blocks,
		];

		return ServiceLocator::getInstance()->get(BuilderService::class)->create($builderData, $this->chat);
	}
}

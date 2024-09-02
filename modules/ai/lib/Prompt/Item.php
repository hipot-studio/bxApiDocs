<?php

namespace Bitrix\AI\Prompt;

use Bitrix\AI\Dto\PromptType;
use Bitrix\AI\Entity\TranslateTrait;
use Bitrix\AI\Facade\User;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Item
{
	use TranslateTrait;
	private Collection $children;

	public function __construct(
		private int $id,
		private ?string $section,
		private string $code,
		private ?string $type,
		private ?string $appCode,
		private ?string $icon,
		private ?string $prompt,
		private mixed $translate,
		private mixed $textTranslate,
		private ?array $settings,
		private ?array $cacheCategory,
		private array $category,
		private bool $workWithResult = false,
	)
	{
		$this->children = new Collection;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getSectionCode(): ?string
	{
		return $this->section;
	}

	public function getSectionTitle(): ?string
	{
		return Section::get($this->section)?->getTitle();
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getType(): ?string
	{
		return $this->type ? PromptType::fromName($this->type) : null;
	}

	public function getAppCode(): ?string
	{
		return $this->appCode;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}

	public function getPrompt(): ?string
	{
		return $this->prompt;
	}

	public function getTitle(): string
	{
		$lang = User::getUserLanguage();
		if (isset($this->translate[$lang]))
		{
			return $this->translate[$lang];
		}
		if (isset($this->translate['en']))
		{
			return $this->translate['en'];
		}

		return $this->code;
	}

	public function getText(): string
	{
		return self::translate($this->textTranslate, User::getUserLanguage());
	}

	public function getCategory(): array
	{
		return is_array($this->category) ? $this->category : [];
	}

	public function getCacheCategory(): array
	{
		return is_array($this->cacheCategory) ? $this->cacheCategory : [];
	}

	public function getSettings(): array
	{
		return is_array($this->settings) ? $this->settings : [];
	}

	public function isWorkWithResult(): bool
	{
		return $this->workWithResult;
	}

	public function isRequiredUserMessage(): bool
	{
		if ($this->prompt)
		{
			return str_contains($this->prompt, '{user_message}');
		}

		return false;
	}

	public function isRequiredOriginalMessage(): bool
	{
		if ($this->prompt)
		{
			return str_contains($this->prompt, '{original_message}');
		}

		return false;
	}

	public function addChild(Item $item): void
	{
		$this->children->push($item);
	}

	public function getChildren(): Collection
	{
		return $this->children;
	}
}

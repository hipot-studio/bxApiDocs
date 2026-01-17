<?php

namespace Bitrix\Crm\MessageSender\UI\ViewChannel;

final class Icon implements \JsonSerializable
{
	public function __construct(
		/** Icon name from ui.icon-set */
		private string $title,
		private string $color,
		private ?string $background = 'var(--ui-color-base-8)',
	)
	{
	}

	public static function telegram(): self
	{
		return new self(
			'telegram',
			'var(--ui-color-base-8)',
			'#28a7e8',
		);
	}

	public static function whatsapp(): self
	{
		return new self(
			'whatsapp',
			'var(--ui-color-base-8)',
			'#25D366',
		);
	}

	public static function sms(): self
	{
		return self::generic();
	}

	public static function generic(): self
	{
		return new self(
			'o-chats',
			'var(--ui-color-base-8)',
			'linear-gradient(336.87deg, #0B83FF 20.35%, #32B2F4 47.08%, #75E4BD 72.85%)',
		);
	}

	public static function notifications(): self
	{
		return new self(
			'chats-24',
			'var(--ui-color-base-8)',
			'linear-gradient(0deg, var(--ui-color-accent-main-primary), var(--ui-color-accent-main-primary)), linear-gradient(153.43deg, rgba(255, 255, 255, 0.4) 0%, rgba(255, 255, 255, 0) 83.33%)',
		);
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getColor(): string
	{
		return $this->color;
	}

	public function setColor(string $color): self
	{
		$this->color = $color;

		return $this;
	}

	public function getBackground(): ?string
	{
		return $this->background;
	}

	public function setBackground(?string $background): self
	{
		$this->background = $background;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'color' => $this->color,
			'background' => $this->background,
		];
	}
}

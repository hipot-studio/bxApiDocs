<?php

namespace Bitrix\Crm\MessageSender\UI\ViewChannel;

use Bitrix\Main\Localization\Loc;

/**
 * Contains concrete appearance details of a channel view model.
 */
final class Appearance implements \JsonSerializable
{
	public static function telegram(): self
	{
		return new self(
			Icon::telegram(),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_TELEGRAM'),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_TELEGRAM'),
		);
	}

	public static function whatsapp(): self
	{
		return new self(
			Icon::whatsapp(),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_WHATSAPP'),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_WHATSAPP'),
		);
	}

	public static function whatsappWaba(): self
	{
		// todo change title
		return self::whatsapp();
	}

	public static function sms(): self
	{
		return new self(
			Icon::sms(),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_SMS'),
			(string)Loc::getMessage('CRM_MESSAGESENDER_UI_APPEARANCE_SMS'),
		);
	}

	public static function generic(string $title): self
	{
		return new self(
			Icon::generic(),
			$title,
			$title,
		);
	}

	public function __construct(
		private Icon $icon,
		private string $title,
		private ?string $subtitle = null,
		private ?string $description = null,
	)
	{
	}

	public function getIcon(): Icon
	{
		return $this->icon;
	}

	public function setIcon(Icon $icon): self
	{
		$this->icon = $icon;

		return $this;
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

	public function getSubtitle(): ?string
	{
		return $this->subtitle;
	}

	public function setSubtitle(?string $subtitle): self
	{
		$this->subtitle = $subtitle;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'icon' => $this->icon,
			'title' => $this->title,
			'subtitle' => $this->subtitle,
			'description' => $this->description,
		];
	}
}

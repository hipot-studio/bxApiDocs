<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Channel\To;

final readonly class Appearance implements \JsonSerializable
{
	public function __construct(
		private string $caption,
		private string $title,
		private string $subtitle,
		private string $avatar,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$caption = $data['caption'] ?? null;
		$title = $data['title'] ?? null;
		$subtitle = $data['subtitle'] ?? null;
		$avatar = $data['avatar'] ?? null;

		if (!is_string($caption) || !is_string($title) || !is_string($subtitle) || !is_string($avatar))
		{
			return null;
		}

		return new self($caption, $title, $subtitle, $avatar);
	}

	public function getCaption(): string
	{
		return $this->caption;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getSubtitle(): string
	{
		return $this->subtitle;
	}

	public function getAvatar(): string
	{
		return $this->avatar;
	}

	public function jsonSerialize(): array
	{
		return [
			'caption' => $this->caption,
			'title' => $this->title,
			'subtitle' => $this->subtitle,
			'avatar' => $this->avatar,
		];
	}
}

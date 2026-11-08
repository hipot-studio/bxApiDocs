<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

final class OpenAppLayoutDto
{
	public function __construct(
		public readonly string $html,
		public readonly string $title,
		public readonly string $errorCode,
		public readonly ?string $iconUrl,
		public readonly ?string $color,
	) {}

	public function toArray(): array
	{
		return [
			'html' => $this->html,
			'title' => $this->title,
			'errorCode' => $this->errorCode,
			'iconUrl' => $this->iconUrl,
			'color' => $this->color,
		];
	}
}

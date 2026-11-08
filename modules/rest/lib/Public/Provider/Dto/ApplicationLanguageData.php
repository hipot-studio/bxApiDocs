<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

final readonly class ApplicationLanguageData
{
	public function __construct(
		private string $languageId,
		private ?string $menuName,
	)
	{
	}

	public function getLanguageId(): string
	{
		return $this->languageId;
	}

	public function getMenuName(): ?string
	{
		return $this->menuName;
	}

	public function toArray(): array
	{
		return [
			'languageId' => $this->languageId,
			'menuName' => $this->menuName,
		];
	}
}

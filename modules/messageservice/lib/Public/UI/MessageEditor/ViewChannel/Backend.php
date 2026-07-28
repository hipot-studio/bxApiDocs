<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;

final readonly class Backend implements \JsonSerializable
{
	public function __construct(
		private string $senderCode,
		private string $id,
		private string $name,
		private string $shortName,
		private bool $isTemplatesBased,
	)
	{
	}

	public function getSenderCode(): string
	{
		return $this->senderCode;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getShortName(): string
	{
		return $this->shortName;
	}

	public function isTemplatesBased(): bool
	{
		return $this->isTemplatesBased;
	}

	public function jsonSerialize(): array
	{
		return [
			'senderCode' => $this->senderCode,
			'id' => $this->id,
		];
	}
}

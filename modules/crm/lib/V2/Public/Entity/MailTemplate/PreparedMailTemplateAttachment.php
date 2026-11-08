<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class PreparedMailTemplateAttachment
{
	public function __construct(
		private int $id,
		private string $name,
		private int $size,
		private ?string $contentType = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function getContentType(): ?string
	{
		return $this->contentType;
	}
}

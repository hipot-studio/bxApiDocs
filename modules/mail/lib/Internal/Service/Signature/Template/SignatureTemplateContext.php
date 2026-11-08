<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

final class SignatureTemplateContext
{
	public function __construct(
		public readonly int $userId,
		public readonly int $mailboxId,
		public readonly string $senderEmail,
		public readonly string $senderName,
		public readonly ?array $imageResources = null,
	)
	{
	}

	/** @param array<string, array{WIDTH: int}> $imageResources */
	public function withImageResources(array $imageResources): self
	{
		return new self(
			$this->userId,
			$this->mailboxId,
			$this->senderEmail,
			$this->senderName,
			$imageResources,
		);
	}
}

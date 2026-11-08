<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\MailTemplate;

final readonly class PreparedTemplate
{
	/**
	 * @param list<PreparedTemplateAttachment> $attachments
	 */
	public function __construct(
		private TemplateReference $reference,
		private string $subject,
		private string $bodyHtml,
		private array $attachments = [],
	)
	{
	}

	public function getReference(): TemplateReference
	{
		return $this->reference;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function getBodyHtml(): string
	{
		return $this->bodyHtml;
	}

	/**
	 * @return list<PreparedTemplateAttachment>
	 */
	public function getAttachments(): array
	{
		return $this->attachments;
	}
}

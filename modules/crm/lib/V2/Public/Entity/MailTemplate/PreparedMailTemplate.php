<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class PreparedMailTemplate
{
	/**
	 * @param list<PreparedMailTemplateAttachment> $attachments
	 */
	public function __construct(
		private int $id,
		private string $subject,
		private string $bodyHtml,
		private array $attachments = [],
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
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
	 * @return list<PreparedMailTemplateAttachment>
	 */
	public function getAttachments(): array
	{
		return $this->attachments;
	}
}

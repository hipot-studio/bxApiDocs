<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class PersonalMailTemplateData
{
	/**
	 * @param list<int> $diskObjectIds
	 */
	public function __construct(
		private string $subject,
		private string $bodyHtml,
		private string $emailFrom,
		private array $diskObjectIds = [],
	)
	{
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function getBodyHtml(): string
	{
		return $this->bodyHtml;
	}

	public function getEmailFrom(): string
	{
		return $this->emailFrom;
	}

	/**
	 * @return list<int>
	 */
	public function getDiskObjectIds(): array
	{
		return $this->diskObjectIds;
	}
}

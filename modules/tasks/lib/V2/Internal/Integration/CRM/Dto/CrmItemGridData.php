<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Dto;

use Bitrix\Tasks\Integration\CRM\Grid\CrmItemGridDataInterface;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemVisibility;

/**
 * Everything a task-list cell may show for one CRM binding. Only the readable state carries the real
 * title and address; for the other two states they stay null, so a cell has nothing protected to leak.
 */
final readonly class CrmItemGridData implements CrmItemGridDataInterface
{
	private function __construct(
		public CrmItemReference $reference,
		public CrmItemVisibility $visibility,
		public string $hiddenTitle,
		public ?string $title = null,
		public ?string $url = null,
		public ?string $typeName = null,
	)
	{
	}

	public static function readable(
		CrmItemReference $reference,
		string $hiddenTitle,
		string $title,
		string $url,
		?string $typeName,
	): self
	{
		return new self(
			$reference,
			CrmItemVisibility::Readable,
			$hiddenTitle,
			$title,
			$url,
			$typeName,
		);
	}

	public static function hidden(CrmItemReference $reference, string $hiddenTitle): self
	{
		return new self($reference, CrmItemVisibility::Hidden, $hiddenTitle);
	}

	public static function notFound(CrmItemReference $reference, string $hiddenTitle): self
	{
		return new self($reference, CrmItemVisibility::NotFound, $hiddenTitle);
	}

	public function isReadable(): bool
	{
		return $this->visibility === CrmItemVisibility::Readable;
	}

	public function isNotFound(): bool
	{
		return $this->visibility === CrmItemVisibility::NotFound;
	}

	/**
	 * The title a cell is allowed to render: the real one for a readable element, the depersonalized one
	 * otherwise.
	 */
	public function getDisplayTitle(): string
	{
		return $this->title ?? $this->hiddenTitle;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getAbbreviation(): string
	{
		return $this->reference->abbreviation;
	}

	public function getTypeId(): int
	{
		return $this->reference->typeId;
	}

	public function getEntityId(): int
	{
		return $this->reference->entityId;
	}

	public function getValue(): string
	{
		return $this->reference->value;
	}
}

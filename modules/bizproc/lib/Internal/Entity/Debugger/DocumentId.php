<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Main\Type\Contract\Arrayable;
use Stringable;

final class DocumentId implements Stringable, Arrayable
{
	private const SEPARATOR = '::';

	public ?string $moduleId;
	public ?string $entity;
	public ?string $documentId;

	public function __construct(
		?string $moduleId,
		?string $entity,
		?string $documentId,
	)
	{
		$this->moduleId = $moduleId;
		$this->entity = $entity;
		$this->documentId = $documentId;
	}

	public static function createFromArray(array $data): self
	{
		return new self(
			$data[0] ?? null,
			$data[1] ?? null,
			$data[2] ?? null,
		);
	}

	public function toArray(): array
	{
		return [$this->moduleId, $this->entity, $this->documentId];
	}

	public function __toString(): string
	{
		return implode(self::SEPARATOR, [
			$this->moduleId,
			$this->entity,
			$this->documentId,
		]);
	}

	public function isEmpty(): bool
	{
		return empty($this->moduleId) && empty($this->entity) && empty($this->documentId);
	}
}

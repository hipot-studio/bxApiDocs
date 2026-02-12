<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Dto;

use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\Type;
use Bitrix\Main\Type\DateTime;

final class CreateDto
{
	private function __construct(
		public readonly string $entityId,
		public readonly LinkEntityType $entityType,
		public readonly int $authorId,
		public readonly string $code = '',
		public readonly Type $type = Type::Custom,
		public readonly DateTime $dateCreate = new DateTime,
		public readonly ?DateTime $dateExpire = null,
		public readonly ?int $maxUses = null,
		public readonly bool $requireApproval = false,
		public readonly ?string $name = null,
	){}

	public static function initForPrimary(string $entityId, LinkEntityType $entityType, int $authorId): self
	{
		return new self(
			entityId: $entityId,
			entityType: $entityType,
			authorId: $authorId,
			type: Type::Primary,
		);
	}

	public static function initForIndividual(string $entityId, LinkEntityType $entityType, int $authorId): self
	{
		return new self(
			entityId: $entityId,
			entityType: $entityType,
			authorId: $authorId,
			type: Type::Individual,
		);
	}

	public function withCode(string $code): self
	{
		return $this->with('code', $code);
	}

	private function with(string $fieldName, mixed $fieldValue): self
	{
		$fields = get_object_vars($this);
		$fields[$fieldName] = $fieldValue;

		return new self(...$fields);
	}

	public function toArray(): array
	{
		return [
			'ENTITY_TYPE' => $this->entityType->value,
			'ENTITY_ID' => $this->entityId,
			'CODE' => $this->code,
			'AUTHOR_ID' => $this->authorId,
			'TYPE' => $this->type->value,
			'DATE_CREATE' => $this->dateCreate,
			'DATE_EXPIRE' => $this->dateExpire,
			'MAX_USES' => $this->maxUses,
			'REQUIRE_APPROVAL' => $this->requireApproval,
			'NAME' => $this->name,
		];
	}
}
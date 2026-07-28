<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Dto;

use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\Type;
use Bitrix\Main\Type\DateTime;

final readonly class CreateDto
{
	private function __construct(
		public string $entityId,
		public LinkEntityType $entityType,
		public int $authorId,
		public string $code = '',
		public Type $type = Type::Custom,
		public DateTime $dateCreate = new DateTime,
		public ?DateTime $dateExpire = null,
		public ?int $maxUses = null,
		public bool $requireApproval = false,
		public ?string $name = null,
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

	public static function initForGuestChat(
		string $chatId,
		int $authorId,
		?int $maxUses = null,
		?DateTime $dateExpire = null,
	): self
	{
		return new self(
			entityId: $chatId,
			entityType: LinkEntityType::GuestChat,
			authorId: $authorId,
			type: Type::Individual,
			dateExpire: $dateExpire,
			maxUses: $maxUses,
		);
	}

	/**
	 * Create DTO for a custom (non-unique) sharing link.
	 *
	 * Custom links allow multiple links per entity, useful for personalized invitations.
	 */
	public static function initForCustom(
		string $entityId,
		LinkEntityType $entityType,
		int $authorId,
		?string $name = null,
		?int $maxUses = null,
	): self
	{
		return new self(
			entityId: $entityId,
			entityType: $entityType,
			authorId: $authorId,
			type: Type::Custom,
			name: $name,
			maxUses: $maxUses,
		);
	}

	public static function initForGuestChatCustom(
		string $chatId,
		int $authorId,
		?string $name = null,
		?int $maxUses = null,
		?DateTime $dateExpire = null,
	): self
	{
		return new self(
			entityId: $chatId,
			entityType: LinkEntityType::GuestChat,
			authorId: $authorId,
			type: Type::Custom,
			dateExpire: $dateExpire,
			maxUses: $maxUses,
			name: $name,
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
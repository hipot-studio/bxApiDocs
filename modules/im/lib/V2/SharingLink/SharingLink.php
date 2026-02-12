<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\ActiveRecord;
use Bitrix\Im\V2\Common\ActiveRecordImplementation;
use Bitrix\Im\V2\Pull\Event\SharingLink\SharingLinkUpdate;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\Entity\ShareableEntity;
use Bitrix\Main\Type\DateTime;

abstract class SharingLink implements RestConvertible, ActiveRecord
{
	use ActiveRecordImplementation;

	protected ?int $id = null;
	protected ?string $entityId = null;
	protected ?string $code = null;
	protected int $authorId = 0;
	protected Type $type = Type::Custom;
	protected ?DateTime $dateCreate = null;
	protected ?DateTime $dateExpire = null;
	protected bool $isRevoked = false;
	protected ?int $maxUses = null;
	protected int $usesCount = 0;
	protected bool $requireApproval = false;
	protected ?string $name = null;

	abstract public function getRecipientsForPull(EventType $type): array;
	abstract public static function getEntityType(): LinkEntityType;
	abstract protected function getUrl(): string;
	abstract public function getEntity(): ?ShareableEntity;
	abstract public function apply(int $userId): Result;

	public function __construct($source)
	{
		$this->initByDefault();

		if (!empty($source))
		{
			$this->load($source);
		}
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): static
	{
		$this->id = $id;
		return $this;
	}

	protected function beforeSaveEntityType(): Result
	{
		$this->getDataEntity()->offsetSet('ENTITY_TYPE', static::getEntityType()->value);

		return new Result();
	}

	public function getEntityId(): ?string
	{
		return $this->entityId;
	}

	public function setEntityId(string $entityId): static
	{
		$this->entityId = $entityId;
		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	protected function setCode(string $code): static
	{
		$this->code = $code;
		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	public function setAuthorId(int $authorId): static
	{
		$this->authorId = $authorId;
		return $this;
	}

	public function getType(): Type
	{
		return $this->type;
	}

	public function setType(string|Type $type): static
	{
		if (is_string($type))
		{
			$this->type = Type::tryFrom($type) ?? Type::Custom;
		}
		else
		{
			$this->type = $type;
		}

		return $this;
	}

	protected function loadTypeFilter(string $type): Type
	{
		return Type::tryFrom($type) ?? Type::Custom;
	}

	protected function saveTypeFilter(Type $type): string
	{
		return $type->value;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(DateTime $dateCreate): static
	{
		$this->dateCreate = $dateCreate;
		return $this;
	}

	public function getDefaultDateCreate(): DateTime
	{
		return new DateTime();
	}

	public function getDateExpire(): ?DateTime
	{
		return $this->dateExpire;
	}

	public function setDateExpire(?DateTime $dateExpire): static
	{
		$this->dateExpire = $dateExpire;
		return $this;
	}

	public function isRevoked(): bool
	{
		return $this->isRevoked;
	}

	public function setIsRevoked(bool $isRevoked): static
	{
		$this->isRevoked = $isRevoked;
		return $this;
	}

	public function getMaxUses(): ?int
	{
		return $this->maxUses;
	}

	public function setMaxUses(?int $maxUses): static
	{
		$this->maxUses = $maxUses;
		return $this;
	}

	public function getUsesCount(): int
	{
		return $this->usesCount;
	}

	public function setUsesCount(int $usesCount): static
	{
		$this->usesCount = $usesCount;
		return $this;
	}

	public function isRequireApproval(): bool
	{
		return $this->requireApproval;
	}

	public function setRequireApproval(bool $requireApproval): static
	{
		$this->requireApproval = $requireApproval;
		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): static
	{
		$this->name = $name;
		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'sharingLink';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'id' => $this->getId(),
			'entityId' => $this->getEntityId(),
			'entityType' => mb_strtolower(static::getEntityType()->value),
			'code' => $this->getCode(),
			'type' => mb_strtolower($this->getType()->value),
			'dateCreate' => $this->getDateCreate(),
			'dateExpire' => $this->getDateExpire(),
			'requireApproval' => $this->isRequireApproval(),
			'url' => $this->getUrl(),
		];
	}

	public function toPullFormat(bool $extendedFormat = false): array
	{
		$baseFields = [
			'id' => $this->getId(),
			'entityId' => $this->getEntityId(),
			'entityType' => mb_strtolower(static::getEntityType()->value),
			'type' => mb_strtolower($this->getType()->value),
			'dateCreate' => $this->getDateCreate(),
			'dateExpire' => $this->getDateExpire(),
			'requireApproval' => $this->isRequireApproval(),
			'url' => $this->getUrl(),
		];

		$additionalFields = [
			'code' => $this->getCode(),
			'authorId' => $this->getAuthorId(),
			'isRevoked' => $this->isRevoked(),
			'maxUses' => $this->getMaxUses(),
			'usesCount' => $this->getUsesCount(),
			'name' => $this->getName(),
		];

		return $extendedFormat ? array_merge($baseFields, $additionalFields) : $baseFields;
	}

	public function getPrimaryId(): ?int
	{
		return $this->getId();
	}

	public function setPrimaryId(int $primaryId): static
	{
		return $this->setId($primaryId);
	}

	public static function getDataClass(): string
	{
		return SharingLinkTable::class;
	}

	protected static function mirrorDataEntityFields(): array
	{
		return [
			'ID' => [
				'primary' => true,
				'field' => 'id',
				'set' => 'setPrimaryId',
				'get' => 'getPrimaryId',
			],
			'ENTITY_TYPE' => [
				'get' => 'getEntityType', /** @see static::getEntityType */
				'beforeSave' => 'beforeSaveEntityType' /** @see static::beforeSaveEntityType */,
			],
			'ENTITY_ID' => [
				'field' => 'entityId',
				'set' => 'setEntityId',
				'get' => 'getEntityId',
			],
			'CODE' => [
				'field' => 'code',
				'set' => 'setCode',
				'get' => 'getCode',
			],
			'AUTHOR_ID' => [
				'field' => 'authorId',
				'set' => 'setAuthorId',
				'get' => 'getAuthorId',
			],
			'TYPE' => [
				'field' => 'type', /** @see static::$type */
				'set' => 'setType', /** @see static::setType() */
				'get' => 'getType', /** @see static::getType() */
				'loadFilter' => 'loadTypeFilter', /** @see static::loadTypeFilter() */
				'saveFilter' => 'saveTypeFilter', /** @see static::saveTypeFilter() */
			],
			'DATE_CREATE' => [
				'field' => 'dateCreate',
				'set' => 'setDateCreate',
				'get' => 'getDateCreate',
				'default' => 'getDefaultDateCreate', /** @see static::getDefaultDateCreate */
			],
			'DATE_EXPIRE' => [
				'field' => 'dateExpire',
				'set' => 'setDateExpire',
				'get' => 'getDateExpire',
				'default' => null,
			],
			'IS_REVOKED' => [
				'field' => 'isRevoked',
				'set' => 'setIsRevoked',
				'get' => 'getIsRevoked',
			],
			'MAX_USES' => [
				'field' => 'maxUses',
				'set' => 'setMaxUses',
				'get' => 'getMaxUses',
				'default' => null,
			],
			'USES_COUNT' => [
				'field' => 'usesCount',
				'set' => 'setUsesCount',
				'get' => 'getUsesCount',
			],
			'REQUIRE_APPROVAL' => [
				'field' => 'requireApproval',
				'set' => 'setRequireApproval',
				'get' => 'getRequireApproval',
			],
			'NAME' => [
				'field' => 'name',
				'set' => 'setName',
				'get' => 'getName',
			],
		];
	}

	public function revoke(): Result
	{
		if ($this->isRevoked())
		{
			return (new Result())->addError(new SharingLinkError(SharingLinkError::ALREADY_REVOKED));
		}

		$this->setIsRevoked(true);

		$saveResult = $this->save();

		if ($saveResult->isSuccess())
		{
			(new SharingLinkUpdate($this))->send();
		}

		return $saveResult;
	}
}

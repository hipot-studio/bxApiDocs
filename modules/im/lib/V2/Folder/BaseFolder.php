<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use ArrayAccess;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Common\ActiveRecordImplementation;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Common\FieldAccessImplementation;
use Bitrix\Im\V2\Common\RegistryEntryImplementation;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\RegistryEntry;
use Bitrix\Im\V2\Result;

abstract class BaseFolder implements ArrayAccess, Folder, RegistryEntry
{
	use FieldAccessImplementation;
	use ActiveRecordImplementation;
	use RegistryEntryImplementation;
	use ContextCustomer;

	protected ?int $id = null;
	protected ?int $userId = null;
	protected int $parentChatId = 0;
	protected string $type = FolderType::Personal->value;
	protected ?string $code = null;
	protected ?string $title = null;
	protected int $sort = 0;

	public function __construct($source = null)
	{
		$this->initByDefault();

		if (!empty($source))
		{
			$this->load($source);
		}
	}

	public function getPrimaryId(): ?int
	{
		return $this->id;
	}

	public function setPrimaryId(int $primaryId): self
	{
		$this->id = $primaryId;

		return $this;
	}

	public static function getDataClass(): string
	{
		return FolderTable::class;
	}

	public static function getRestEntityName(): string
	{
		return 'folder';
	}

	public function checkAccess(?int $userId = null): Result
	{
		$result = new Result();

		$contextUserId = $userId ?? $this->getContext()->getUserId();
		$ownerId = $this->getUserId();

		if (!$contextUserId || !$ownerId || $ownerId !== $contextUserId)
		{
			return $result->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		return $result;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getParentId(): int
	{
		return $this->parentChatId;
	}

	public function setParentChatId(?int $parentChatId): self
	{
		$this->parentChatId = (int)($parentChatId ?? 0);

		return $this;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function setType(?string $type): self
	{
		if ($type !== null)
		{
			$this->type = $type;
		}

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	/** REST code; defaults to the domain getCode(). Subclasses override when the wire code must differ from the stored one. */
	public function getRestCode(): ?string
	{
		return $this->getCode();
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title ?? '';
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function setSort(?int $sort): self
	{
		$this->sort = (int)($sort ?? 0);

		return $this;
	}

	public function displaysNestedInRoot(): bool
	{
		return false;
	}

	public function getRecentParentScope(): ?int
	{
		return $this->getParentId() === 0 && $this->displaysNestedInRoot()
			? null
			: $this->getParentId();
	}

	/**
	 * @return array<array>
	 */
	protected static function mirrorDataEntityFields(): array
	{
		return [
			'ID' => [
				'primary' => true,
				'field' => 'id',
				'set' => 'setId',
				'get' => 'getId',
			],
			'USER_ID' => [
				'field' => 'userId',
				'set' => 'setUserId',
				'get' => 'getUserId',
			],
			'PARENT_CHAT_ID' => [
				'field' => 'parentChatId',
				'set' => 'setParentChatId',
				'get' => 'getParentId',
			],
			'TYPE' => [
				'field' => 'type',
				'set' => 'setType',
				'get' => 'getType',
			],
			'CODE' => [
				'field' => 'code',
				'set' => 'setCode',
				'get' => 'getCode',
				'nullable' => true,
			],
			'TITLE' => [
				'field' => 'title',
				'set' => 'setTitle',
				'nullable' => true,
			],
			'SORT' => [
				'field' => 'sort',
				'set' => 'setSort',
				'get' => 'getSort',
			],
		];
	}
}

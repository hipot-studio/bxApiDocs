<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;

/**
 * Private rule of the file format policy for one addressee: an employee or a department.
 * The access code is derived from the addressee and is what the rule is matched by; the extensions
 * are the applied part, presets are the state of the settings interface.
 */
final class FormatPolicyRule implements EntityInterface
{
	public const ENTITY_TYPE_USER = 'user';
	public const ENTITY_TYPE_DEPARTMENT = 'department';
	public const ENTITY_TYPE_TEAM = 'team';

	public const MODE_APPEND = 'append';
	public const MODE_REPLACE = 'replace';

	protected ?int $id = null;
	protected string $entityType = '';
	protected int $entityId = 0;
	protected bool $includeNested = false;
	protected string $accessCode = '';
	protected string $mode = self::MODE_APPEND;
	/** @var string[] */
	protected array $extensions = [];
	/** @var string[] */
	protected array $presets = [];
	protected bool $blockNoExtension = false;
	protected int $savedAt = 0;
	protected int $updatedBy = 0;

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	/**
	 * @param string $entityType One of ENTITY_TYPE_* constants.
	 * @return self
	 */
	public function setEntityType(string $entityType): self
	{
		$this->entityType = $entityType;

		return $this;
	}

	public function getEntityType(): string
	{
		return $this->entityType;
	}

	public function setEntityId(int $entityId): self
	{
		$this->entityId = $entityId;

		return $this;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}

	public function setIncludeNested(bool $includeNested): self
	{
		$this->includeNested = $includeNested;

		return $this;
	}

	public function isIncludeNested(): bool
	{
		return $this->includeNested;
	}

	public function setAccessCode(string $accessCode): self
	{
		$this->accessCode = $accessCode;

		return $this;
	}

	public function getAccessCode(): string
	{
		return $this->accessCode;
	}

	/**
	 * @param string $mode One of MODE_* constants.
	 * @return self
	 */
	public function setMode(string $mode): self
	{
		$this->mode = $mode;

		return $this;
	}

	public function getMode(): string
	{
		return $this->mode;
	}

	/**
	 * @param string[] $extensions
	 * @return self
	 */
	public function setExtensions(array $extensions): self
	{
		$this->extensions = $extensions;

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getExtensions(): array
	{
		return $this->extensions;
	}

	/**
	 * @param string[] $presets
	 * @return self
	 */
	public function setPresets(array $presets): self
	{
		$this->presets = $presets;

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getPresets(): array
	{
		return $this->presets;
	}

	public function setBlockNoExtension(bool $blockNoExtension): self
	{
		$this->blockNoExtension = $blockNoExtension;

		return $this;
	}

	public function isBlockNoExtension(): bool
	{
		return $this->blockNoExtension;
	}

	/**
	 * @param int $savedAt Unix timestamp of the last save of the rule.
	 * @return self
	 */
	public function setSavedAt(int $savedAt): self
	{
		$this->savedAt = $savedAt;

		return $this;
	}

	public function getSavedAt(): int
	{
		return $this->savedAt;
	}

	public function setUpdatedBy(int $updatedBy): self
	{
		$this->updatedBy = $updatedBy;

		return $this;
	}

	public function getUpdatedBy(): int
	{
		return $this->updatedBy;
	}
}

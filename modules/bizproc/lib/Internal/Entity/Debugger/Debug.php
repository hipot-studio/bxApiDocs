<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Debugger;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;
use Exception;

final class Debug implements EntityInterface
{
	private ?int $id = null;
	private int $userId;
	private int $templateId;
	private ?DocumentId $documentId = null;
	private bool $enabled = true;
	private ?DateTime $createdAt = null;
	private ?DateTime $updatedAt = null;

	private function __construct() {}

	/**
	 * @param string[]|null $documentId
	 */
	public static function create(
		int $userId,
		int $templateId,
		?array $documentId = null,
	): self
	{
		$debug = new self();
		$debug->userId = $userId;
		$debug->templateId = $templateId;
		$debug->documentId = DocumentId::createFromArray($documentId ?? []);
		$debug->enabled = true;
		$debug->createdAt = new DateTime();
		$debug->updatedAt = new DateTime();

		return $debug;
	}

	/**
	 * @param array{ id:int, user_id:int, template_id:int, module_id?:string, entity?:string,
	 *     document_id?:string, enabled?:string, created_at?:string, updated_at?:string } $props
	 *
	 * @return self
	 */
	public static function mapFromArray(array $props): self
	{
		$debug = new self();

		if (isset($props['id']))
		{
			$debug->id = (int)$props['id'];
		}

		$debug->userId = (int)($props['user_id'] ?? 0);
		$debug->templateId = (int)($props['template_id'] ?? 0);
		$debug->documentId = new DocumentId(
			$props['module_id'] ?? null,
			$props['entity'] ?? null,
			$props['document_id'] ?? null,
		);
		$debug->enabled = ($props['enabled'] ?? 'Y') === 'Y';

		if (isset($props['created_at']))
		{
			try
			{
				$debug->createdAt = new DateTime($props['created_at']);
			}
			catch (Exception)
			{
				$debug->createdAt = null;
			}
		}

		if (isset($props['updated_at']))
		{
			try
			{
				$debug->updatedAt = new DateTime($props['updated_at']);
			}
			catch (Exception)
			{
				$debug->updatedAt = null;
			}
		}

		return $debug;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTemplateId(): int
	{
		return $this->templateId;
	}

	public function getDocumentId(): ?DocumentId
	{
		return $this->documentId;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function setEnabled(bool $enabled): self
	{
		$this->enabled = $enabled;

		return $this;
	}

	public function getCreatedAt(): ?DateTime
	{
		return $this->createdAt;
	}

	public function setCreatedAt(DateTime $createdAt): self
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): ?DateTime
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(DateTime $updatedAt): self
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}

	public function matches(int $userId, int $templateId, ?array $documentId = null): bool
	{
		if ($this->userId !== $userId)
		{
			return false;
		}

		if ($this->templateId !== $templateId)
		{
			return false;
		}

		if ($this->documentId !== null)
		{
			if ($documentId === null || count($documentId) !== 3)
			{
				return false;
			}

			return $this->documentId->moduleId === $documentId[0]
				&& $this->documentId->entity === $documentId[1]
				&& $this->documentId->documentId === $documentId[2];
		}

		return true;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'template_id' => $this->templateId,
			'module_id' => $this->documentId?->moduleId,
			'entity' => $this->documentId?->entity,
			'document_id' => $this->documentId?->documentId,
			'enabled' => $this->enabled ? 'Y' : 'N',
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\StorageItem;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldCodeService;

class StorageItem implements EntityInterface
{
	private ?int $id = null;
	private ?int $createdBy = null;
	private ?int $updatedBy = null;
	private ?int $createdAt = null;
	private ?int $updatedAt = null;
	private ?string $code = null;
	private ?string $documentId = null;
	private ?string $workflowId = null;
	private ?int $templateId = null;
	private ?int $storageId = null;
	private array $valueFields = [];

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getCreatedBy(): ?int
	{
		return $this->createdBy;
	}

	public function setCreatedBy(?int $createdBy): self
	{
		$this->createdBy = $createdBy;

		return $this;
	}

	public function getUpdatedBy(): ?int
	{
		return $this->updatedBy;
	}

	public function setUpdatedBy(?int $updatedBy): self
	{
		$this->updatedBy = $updatedBy;

		return $this;
	}

	public function getCreatedAt(): ?int
	{
		return $this->createdAt;
	}

	public function setCreatedAt(?int $createdAt): self
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): ?int
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(?int $updatedAt): self
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getDocumentId(): ?string
	{
		return $this->documentId;
	}

	public function setDocumentId(?string $documentId): self
	{
		$this->documentId = $documentId;

		return $this;
	}

	public function getWorkflowId(): ?string
	{
		return $this->workflowId;
	}

	public function setWorkflowId(?string $workflowId): self
	{
		$this->workflowId = $workflowId;

		return $this;
	}

	public function getTemplateId(): ?int
	{
		return $this->templateId;
	}

	public function setTemplateId(?int $templateId): self
	{
		$this->templateId = $templateId;

		return $this;
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function setStorageId(?int $storageId): self
	{
		$this->storageId = $storageId;

		return $this;
	}

	public function setValueFields(array $fields): self
	{
		$this->valueFields = $fields;

		return $this;
	}

	public function getValueFields(): array
	{
		return $this->valueFields;
	}

	public function getValueField(string $key)
	{
		return $this->valueFields[$key] ?? null;
	}

	public function setValueField(string $key, $value): self
	{
		$this->valueFields[$key] = $value;

		return $this;
	}

	public static function mapFromArray(array $props, ?int $storageTypeId = null): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}

		if (isset($props['createdBy']))
		{
			$result->setCreatedBy((int)$props['createdBy']);
		}

		if (isset($props['createdAt']))
		{
			$result->setCreatedAt((int)$props['createdAt']);
		}

		if (isset($props['updatedBy']))
		{
			$result->setUpdatedBy((int)$props['updatedBy']);
		}

		if (isset($props['updatedAt']))
		{
			$result->setUpdatedAt((int)$props['updatedAt']);
		}

		if (isset($props['code']))
		{
			$result->setCode((string)$props['code']);
		}

		if (isset($props['documentId']))
		{
			$result->setDocumentId((string)$props['documentId']);
		}

		if (isset($props['workflowId']))
		{
			$result->setWorkflowId((string)$props['workflowId']);
		}

		if (isset($props['templateId']))
		{
			$result->setTemplateId((int)$props['templateId']);
		}

		if ($storageTypeId)
		{
			$fieldCodes = (new FieldCodeService())->getFieldCodes($storageTypeId);
			if ($fieldCodes)
			{
				foreach ($fieldCodes as $code)
				{
					$result->setValueField($code, $props[$code] ?? null);
				}
			}
		}

		return $result;
	}

	public function __call(string $name, array $args)
	{
		if (str_starts_with($name, 'get'))
		{
			$field = lcfirst(substr($name, 3));
			if (array_key_exists($field, $this->valueFields))
			{
				return $this->valueFields[$field];
			}
		}

		return null;
	}

	public function toArray(): array
	{
		return array_merge([
			'id' => $this->id,
			'createdBy' => $this->createdBy,
			'updatedBy' => $this->updatedBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'code' => $this->code,
			'documentId' => $this->documentId,
			'workflowId' => $this->workflowId,
			'templateId' => $this->templateId,
		], $this->valueFields);
	}
}

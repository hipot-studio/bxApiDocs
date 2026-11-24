<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class RowRule implements EntityInterface
{
	private ?int $id = null;

	/**
	 * @param string $tableCode
	 * @param string $fieldCode
	 * @param array $values Canonical items: ['k' => 'c'|'me'|'dept'|'dept_tree'|'led_dept_tree', 'v' => ?string].
	 * @param string|null $description
	 * @param int|null $createdById
	 * @param int|null $updatedById
	 * @param DateTime|null $dateCreate
	 * @param DateTime|null $dateModify
	 */
	public function __construct(
		private string $tableCode,
		private string $fieldCode,
		private array $values,
		private ?string $description = null,
		private ?int $createdById = null,
		private ?int $updatedById = null,
		private ?DateTime $dateCreate = null,
		private ?DateTime $dateModify = null,
	)
	{
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

	public function getTableCode(): string
	{
		return $this->tableCode;
	}

	public function setTableCode(string $tableCode): self
	{
		$this->tableCode = $tableCode;

		return $this;
	}

	public function getFieldCode(): string
	{
		return $this->fieldCode;
	}

	public function setFieldCode(string $fieldCode): self
	{
		$this->fieldCode = $fieldCode;

		return $this;
	}

	public function getValues(): array
	{
		return $this->values;
	}

	public function setValues(array $values): self
	{
		$this->values = $values;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getCreatedById(): ?int
	{
		return $this->createdById;
	}

	public function setCreatedById(?int $createdById): self
	{
		$this->createdById = $createdById;

		return $this;
	}

	public function getUpdatedById(): ?int
	{
		return $this->updatedById;
	}

	public function setUpdatedById(?int $updatedById): self
	{
		$this->updatedById = $updatedById;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(?DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getDateModify(): ?DateTime
	{
		return $this->dateModify;
	}

	public function setDateModify(?DateTime $dateModify): self
	{
		$this->dateModify = $dateModify;

		return $this;
	}
}

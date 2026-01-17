<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Public\Service\Document\InspectorService;

final class Document
{
	public readonly array $complexId;
	public readonly array $complexType;
	private ?array $changedFieldNames = null;

	public function __construct(array $complexDocumentId, ?array $complexType = null)
	{
		$this->complexId = $complexDocumentId;

		$validComplexType = (new InspectorService())->getValidComplexType($complexDocumentId, $complexType);
		$this->complexType = is_array($validComplexType) ? $validComplexType : [];
	}

	public function setChangedFieldNames(array $changedFieldNames): self
	{
		$this->changedFieldNames = $changedFieldNames;

		return $this;
	}

	public function getModuleId(): string
	{
		return $this->complexId[0] ?? '';
	}

	public function getEntity(): string
	{
		return $this->complexId[1] ?? '';
	}

	public function getType(): string
	{
		return $this->complexType[2] ?? '';
	}

	public function getId(): mixed
	{
		return $this->complexId[2];
	}

	public function hasChangedFields(): bool
	{
		return (bool)$this->changedFieldNames;
	}

	public function getChangedFieldNames(): array
	{
		return $this->changedFieldNames ?? [];
	}

	public function isFieldChanged(string $name): bool
	{
		return in_array($name, $this->getChangedFieldNames(), true);
	}
}

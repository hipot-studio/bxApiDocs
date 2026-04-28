<?php

namespace Bitrix\Crm\Import\Dto\Entity;

use Bitrix\Crm\Import\Enum\Delimiter;
use Bitrix\Crm\Import\Enum\Encoding;
use Bitrix\Crm\Import\Enum\NameFormat;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

abstract class AbstractImportSettings implements Arrayable, JsonSerializable
{
	protected ?string $importFileId = null;
	protected Encoding $encoding;
	protected int $defaultResponsibleId;
	protected NameFormat $nameFormat = NameFormat::FirstLast;
	protected Delimiter $delimiter = Delimiter::Semicolon;
	protected bool $isFirstRowHasHeaders = true;
	protected bool $isSkipEmptyColumns = true;
	protected ?int $categoryId = null;

	protected ?FieldBindings $fieldBindings = null;

	public function __construct()
	{
		$this->defaultResponsibleId = Container::getInstance()->getContext()->getUserId();
		$this->encoding = Encoding::defaultByLanguage(Application::getInstance()->getContext()->getLanguage());
	}

	abstract public function getEntityTypeId(): int;

	public function getImportFileId(): ?string
	{
		return $this->importFileId;
	}

	public function setImportFileId(string $fileId): static
	{
		$this->importFileId = $fileId;

		return $this;
	}

	public function getEncoding(): Encoding
	{
		return $this->encoding;
	}

	public function setEncoding(Encoding $encoding): static
	{
		$this->encoding = $encoding;

		return $this;
	}

	public function getDefaultResponsibleId(): int
	{
		return $this->defaultResponsibleId;
	}

	public function setDefaultResponsibleId(int $defaultResponsibleId): static
	{
		$this->defaultResponsibleId = $defaultResponsibleId;

		return $this;
	}

	public function getNameFormat(): NameFormat
	{
		return $this->nameFormat;
	}

	public function setNameFormat(NameFormat $nameFormat): static
	{
		$this->nameFormat = $nameFormat;

		return $this;
	}

	public function getDelimiter(): Delimiter
	{
		return $this->delimiter;
	}

	public function setDelimiter(Delimiter $delimiter): static
	{
		$this->delimiter = $delimiter;

		return $this;
	}

	public function isFirstRowHasHeaders(): bool
	{
		return $this->isFirstRowHasHeaders;
	}

	public function setIsFirstRowHasHeaders(bool $isFirstRowHasHeaders): static
	{
		$this->isFirstRowHasHeaders = $isFirstRowHasHeaders;

		return $this;
	}

	public function isSkipEmptyColumns(): bool
	{
		return $this->isSkipEmptyColumns;
	}

	public function setIsSkipEmptyColumns(bool $isSkipEmptyColumns): static
	{
		$this->isSkipEmptyColumns = $isSkipEmptyColumns;

		return $this;
	}

	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function setCategoryId(?int $categoryId): self
	{
		$this->categoryId = $categoryId;

		return $this;
	}

	public function getFieldBindings(): ?FieldBindings
	{
		return $this->fieldBindings;
	}

	public function setFieldBindings(?FieldBindings $fieldBindings): static
	{
		$this->fieldBindings = $fieldBindings;

		return $this;
	}

	public function fill(array $importSettings): static
	{
		if (!empty($importSettings['importFileId']))
		{
			$this->setImportFileId($importSettings['importFileId']);
		}

		if (
			isset($importSettings['encoding'])
			&& Encoding::tryFrom($importSettings['encoding']) !== null
		)
		{
			$this->setEncoding(Encoding::tryFrom($importSettings['encoding']));
		}

		if (
			isset($importSettings['defaultResponsibleId'])
			&& is_numeric($importSettings['defaultResponsibleId'])
			&& (int)$importSettings['defaultResponsibleId'] > 0
		)
		{
			$this->setDefaultResponsibleId((int)$importSettings['defaultResponsibleId']);
		}

		if (
			isset($importSettings['nameFormat'])
			&& NameFormat::tryFrom($importSettings['nameFormat']) !== null
		)
		{
			$this->setNameFormat(NameFormat::tryFrom($importSettings['nameFormat']));
		}

		if (
			isset($importSettings['delimiter'])
			&& Delimiter::tryFrom($importSettings['delimiter']) !== null
		)
		{
			$this->setDelimiter(Delimiter::tryFrom($importSettings['delimiter']));
		}

		if (isset($importSettings['isFirstRowHasHeaders']))
		{
			$this->setIsFirstRowHasHeaders((bool)$importSettings['isFirstRowHasHeaders']);
		}

		if (isset($importSettings['isSkipEmptyColumns']))
		{
			$this->setIsSkipEmptyColumns((bool)$importSettings['isSkipEmptyColumns']);
		}

		if (isset($importSettings['fieldBindings']) && is_array($importSettings['fieldBindings']))
		{
			$rawFieldBindings = $importSettings['fieldBindings'];

			$fieldBindings = FieldBindings::tryFromArray($rawFieldBindings);
			if ($fieldBindings !== null)
			{
				$this->setFieldBindings($fieldBindings);
			}
		}

		if (isset($importSettings['categoryId']) && is_numeric($importSettings['categoryId']))
		{
			$categoryId = (int)$importSettings['categoryId'];
			$category = Container::getInstance()->getFactory($this->getEntityTypeId())?->getCategory($categoryId);

			$this->setCategoryId($category?->getId());
		}

		return $this;
	}

	public function applyDefaultValues(array $values): array
	{
		if (($values[Item::FIELD_NAME_ASSIGNED] ?? null) === null)
		{
			$values[Item::FIELD_NAME_ASSIGNED] = $this->getDefaultResponsibleId();
		}

		return $values;
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->getEntityTypeId(),
			'importFileId' => $this->getImportFileId(),
			'encoding' => $this->getEncoding()->value,
			'defaultResponsibleId' => $this->getDefaultResponsibleId(),
			'nameFormat' => $this->getNameFormat()->value,
			'delimiter' => $this->getDelimiter()->value,
			'isFirstRowHasHeaders' => $this->isFirstRowHasHeaders(),
			'isSkipEmptyColumns' => $this->isSkipEmptyColumns(),
			'categoryId' => $this->getCategoryId(),
			'fieldBindings' => $this->getFieldBindings()?->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}

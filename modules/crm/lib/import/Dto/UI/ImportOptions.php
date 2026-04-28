<?php

namespace Bitrix\Crm\Import\Dto\UI;

use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class ImportOptions implements Arrayable, JsonSerializable
{
	private readonly Dictionary $dictionary;
	private readonly AbstractImportSettings $importSettings;

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	public function setDictionary(Dictionary $dictionary): self
	{
		$this->dictionary = $dictionary;

		return $this;
	}

	public function setImportSettings(AbstractImportSettings $importSettings): self
	{
		$this->importSettings = $importSettings;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'dictionary' => $this->dictionary->toArray(),
			'importSettings' => $this->importSettings->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}

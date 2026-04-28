<?php

namespace Bitrix\Crm\Import\Controller\Response;

use Bitrix\Crm\Import\Dto\UI\Table;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class ImportResponse implements Arrayable, JsonSerializable
{
	public function __construct(
		public int $successImportCount,
		public int $failImportCount,
		public int $duplicateImportCount,
		public int $currentLine,
		public int $progressedBytes,
		public bool $isFinished,
		public ?Table $errorsPreviewTable,
		public ?string $downloadFailImportFileUrl,
		public ?string $downloadDuplicateImportFileUrl,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'successImportCount' => $this->successImportCount,
			'failImportCount' => $this->failImportCount,
			'duplicateImportCount' => $this->duplicateImportCount,
			'currentLine' => $this->currentLine,
			'progressedBytes' => $this->progressedBytes,
			'isFinished' => $this->isFinished,
			'errorsPreviewTable' => $this->errorsPreviewTable?->toArray(),
			'downloadFailImportFileUrl' => $this->downloadFailImportFileUrl,
			'downloadDuplicateImportFileUrl' => $this->downloadDuplicateImportFileUrl,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}

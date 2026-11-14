<?php

declare(strict_types=1);

namespace Bitrix\AI\Cloud\PromptDb;

final class PromptDbLogContext
{
	public function __construct(
		private readonly ?int $sourceVersion = null,
		private readonly ?int $boxVersion = null,
		private readonly ?string $mode = null,
		private readonly ?int $fileSize = null,
		private readonly ?string $fallbackReason = null,
		private readonly ?string $errorType = null,
		private readonly ?string $unsupportedMode = null,
	)
	{
	}

	public function toArray(): array
	{
		$context = [];

		if ($this->sourceVersion !== null)
		{
			$context['sourceVersion'] = $this->sourceVersion;
		}

		if ($this->boxVersion !== null)
		{
			$context['boxVersion'] = $this->boxVersion;
		}

		if ($this->mode !== null)
		{
			$context['mode'] = $this->mode;
		}

		if ($this->fileSize !== null)
		{
			$context['fileSize'] = $this->fileSize;
		}

		if ($this->fallbackReason !== null)
		{
			$context['fallbackReason'] = $this->fallbackReason;
		}

		if ($this->errorType !== null)
		{
			$context['errorType'] = $this->errorType;
		}

		if ($this->unsupportedMode !== null)
		{
			$context['unsupportedMode'] = $this->unsupportedMode;
		}

		return $context;
	}
}

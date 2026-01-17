<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config;


class DeleteConfig
{
	private int $userId;
	private bool $unsafeDelete;
	private bool $deleteSubTemplates;
	private RuntimeData $runtimeData;

	public function __construct(
		int $userId,
		bool $unsafeDelete = false,
		bool $deleteSubTemplates = false,
		RuntimeData $runtime = new RuntimeData()
	)
	{
		$this->userId = $userId;
		$this->unsafeDelete = $unsafeDelete;
		$this->deleteSubTemplates = $deleteSubTemplates;
		$this->runtimeData = $runtime;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function isUnsafeDelete(): bool
	{
		return $this->unsafeDelete;
	}

	public function isDeleteSubTemplates(): bool
	{
		return $this->deleteSubTemplates;
	}

	public function getRuntime(): RuntimeData
	{
		return $this->runtimeData;
	}
}

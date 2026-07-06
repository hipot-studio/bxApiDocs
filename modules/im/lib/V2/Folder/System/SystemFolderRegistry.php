<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\System;

class SystemFolderRegistry
{
	/** @var SystemFolderPrototype[]|null lazy-built canonical list */
	private ?array $prototypes = null;

	/**
	 * @return SystemFolderPrototype[]
	 */
	public function getAll(): array
	{
		if ($this->prototypes !== null)
		{
			return $this->prototypes;
		}

		$this->prototypes = [
			new SystemFolderPrototype(DefaultFolder::CODE, static fn (): SystemFolder => new DefaultFolder()),
			new SystemFolderPrototype(TaskFolder::CODE, static fn (): SystemFolder => new TaskFolder()),
			new SystemFolderPrototype(CopilotFolder::CODE, static fn (): SystemFolder => new CopilotFolder()),
			new SystemFolderPrototype(AllOpenChannelFolder::CODE, static fn (): SystemFolder => new AllOpenChannelFolder()),
			new SystemFolderPrototype(CollabFolder::CODE, static fn (): SystemFolder => new CollabFolder()),
			new SystemFolderPrototype(OpenLineFolder::CODE, static fn (): SystemFolder => new OpenLineFolder()),
		];

		return $this->prototypes;
	}

	/**
	 * Returns prototype for the given code, or null if unknown.
	 */
	public function findByCode(string $code): ?SystemFolderPrototype
	{
		foreach ($this->getAll() as $prototype)
		{
			if ($prototype->code === $code)
			{
				return $prototype;
			}
		}

		return null;
	}
}

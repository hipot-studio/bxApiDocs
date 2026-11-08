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
			new SystemFolderPrototype(AllFolder::CODE, static fn (): SystemFolder => new AllFolder()),
			new SystemFolderPrototype(ChatFolder::CODE, static fn (): SystemFolder => new ChatFolder()),
			new SystemFolderPrototype(ChannelFolder::CODE, static fn (): SystemFolder => new ChannelFolder()),
			new SystemFolderPrototype(TaskRootFolder::CODE, static fn (): SystemFolder => new TaskRootFolder()),
			new SystemFolderPrototype(VibecodeAppFolder::CODE, static fn (): SystemFolder => new VibecodeAppFolder()),
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

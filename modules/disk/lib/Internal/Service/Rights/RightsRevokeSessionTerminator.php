<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Rights;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\SessionTerminationServiceFactory;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Application;

/**
 * Ends the sessions of editing that a revoke of rights has left without a right behind them, the way the
 * popup "Access" does it after a save: a recipient whose right was taken back must not go on editing the
 * document in an editor opened before.
 *
 * Whether a session still has its right is read from the materialized rights, which RightsManager rebuilds
 * in background jobs of its own. The job below therefore runs after those - its priority is below every one
 * RightsManager hands out - and the boards inside a folder are looked for there as well, so that the request
 * that revoked the rights does not walk the subtree.
 */
class RightsRevokeSessionTerminator
{
	private const JOB_PRIORITY = Application::JOB_PRIORITY_LOW;

	public function scheduleFor(BaseObject $object, int $operatorId): void
	{
		Application::getInstance()->addBackgroundJob(
			function () use ($object, $operatorId): void {
				$this->terminate($object);

				if ($object instanceof Folder)
				{
					foreach ($this->findBoardsInside($object, $operatorId) as $board)
					{
						$this->terminate($board);
					}
				}
			},
			[],
			self::JOB_PRIORITY,
		);
	}

	private function terminate(BaseObject $object): void
	{
		foreach ((new SessionTerminationServiceFactory($object))->createAll() as $sessionTerminationService)
		{
			$sessionTerminationService->terminateSessionsWithInsufficientRights();
		}
	}

	/**
	 * @return BaseObject[]
	 */
	private function findBoardsInside(Folder $folder, int $operatorId): array
	{
		$storage = $folder->getStorage();
		if (!$storage)
		{
			return [];
		}

		$boards = [];
		$descendants = $folder->getDescendants($storage->getSecurityContext($operatorId), [
			'filter' => [
				'=TYPE' => ObjectTable::TYPE_FILE,
				'@TYPE_FILE' => [TypeFile::FLIPCHART, TypeFile::DOCUMENT, TypeFile::PDF],
			],
		]);
		foreach ($descendants as $descendant)
		{
			$realObject = $descendant->getRealObject();
			if ($realObject instanceof BaseObject)
			{
				$boards[] = $realObject;
			}
		}

		return $boards;
	}
}

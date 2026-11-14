<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\Document\SessionTerminationServiceFactory;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Application;

final class LegacyFolderSessionTerminationScheduler implements FolderSessionTerminationScheduler
{
	public function prepare(Folder $folder, SecurityContext $securityContext): FolderSessionTerminationPlan
	{
		$objects = $folder->getDescendants($securityContext, [
			'filter' => [
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=TYPE_FILE' => TypeFile::FLIPCHART,
			],
		]);
		$boards = [];
		foreach ($objects as $object)
		{
			$boards[] = $object->getRealObject();
		}

		return new FolderSessionTerminationPlan($folder, $boards);
	}

	public function schedule(FolderSessionTerminationPlan $plan): void
	{
		Application::getInstance()->addBackgroundJob(
			static function () use ($plan): void {
				$service = (new SessionTerminationServiceFactory($plan->folder))->create();
				$service->terminateSessionsWithInsufficientRights();
			},
		);

		if ($plan->boards !== [])
		{
			Application::getInstance()->addBackgroundJob(
				static function () use ($plan): void {
					foreach ($plan->boards as $board)
					{
						$service = (new SessionTerminationServiceFactory($board))->create();
						$service->terminateSessionsWithInsufficientRights();
					}
				},
			);
		}
	}
}

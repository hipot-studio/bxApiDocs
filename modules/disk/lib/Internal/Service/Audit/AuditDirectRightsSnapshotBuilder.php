<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\RightsManager;
use RuntimeException;

final class AuditDirectRightsSnapshotBuilder
{
	public function __construct(private readonly ?RightsManager $rightsManager = null)
	{
	}

	public function build(BaseObject $object): array
	{
		$realObject = $object->getRealObject();
		if ($realObject === null)
		{
			throw new RuntimeException('Audit rights object is unavailable.');
		}
		$manager = $this->rightsManager ?? Driver::getInstance()->getRightsManager();
		$taskNames = array_column($manager->getTasks(), 'NAME', 'ID');
		$snapshot = [];
		foreach ($manager->getSpecificRights($realObject) as $right)
		{
			$taskId = (int)$right['TASK_ID'];
			if (!isset($taskNames[$taskId]))
			{
				continue;
			}
			$snapshot[] = [
				'accessCode' => (string)$right['ACCESS_CODE'],
				'domain' => (string)($right['DOMAIN'] ?? ''),
				'taskId' => $taskId,
				'taskName' => (string)$taskNames[$taskId],
				'negative' => (bool)($right['NEGATIVE'] ?? false),
			];
		}

		return $snapshot;
	}
}

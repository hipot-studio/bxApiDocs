<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\UnifiedLink;

use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internal\Service\Sync\Access\LinkAccessPublisher;
use Bitrix\Main\ORM\Data\AddResult;

class SetAccessCommandHandler
{
	public function __invoke(SetAccessCommand $command): AddResult
	{
		$publisher = new LinkAccessPublisher();
		// the level that stands now is what tells a revocation from an edit, and the write takes it away
		$levelBefore = $publisher->captureUnifiedLevel($command->objectId);

		$result = UnifiedLinkAccessRepository::set(
			$command->objectId,
			$command->level,
		);

		if ($result->isSuccess())
		{
			$publisher->publishUnifiedLinkLevelChanged($command->objectId, $levelBefore, $command->level);
		}

		return $result;
	}
}

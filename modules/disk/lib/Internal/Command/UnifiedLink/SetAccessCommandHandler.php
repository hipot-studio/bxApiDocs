<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditUnifiedLinkEventProducer;
use Bitrix\Disk\Internal\Service\Sync\Access\LinkAccessPublisher;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Public\Event\Audit\AuditLinkEvent;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Throwable;

class SetAccessCommandHandler
{
	public function __invoke(SetAccessCommand $command): AddResult
	{
		$result = new AddResult();
		$publisher = new LinkAccessPublisher();

		try
		{
			$transactionValue = AuditTransaction::run(
				function (AuditTransaction $owner) use ($command, &$result, $publisher): AddResult|false {
					$object = BaseObject::loadById($command->objectId);
					if (!$object instanceof File && !$object instanceof Folder)
					{
						$result->addError(new Error('Could not find object for unified link access change.'));

						return false;
					}

					$oldAccessLevel = UnifiedLinkAccessRepository::getByObjectId($command->objectId)
						?? Configuration::getDefaultAccessLevelForObject($object)
					;
					if ($oldAccessLevel === $command->level)
					{
						return $result;
					}

					// the level that stands now is what tells a revocation from an edit, and the write takes it away
					$levelBefore = $publisher->captureUnifiedLevel($command->objectId);

					$result = UnifiedLinkAccessRepository::set($command->objectId, $command->level);
					if (!$result->isSuccess())
					{
						return false;
					}

					$publisher->publishUnifiedLinkLevelChanged($command->objectId, $levelBefore, $command->level);

					(new AuditUnifiedLinkEventProducer(
						static function (AuditLinkEvent $event) use ($owner): Result {
							$owner->tryAddAfterConfirmedCommit(
								static fn(): Result => AuditCaptureService::runFailOpen(
									static function () use ($event): Result {
										$event->send();

										return new Result();
									},
								),
							);

							return new Result();
						},
					))->publishAccessChanged(
						object: $object,
						oldAccessLevel: $oldAccessLevel,
						newAccessLevel: $command->level,
						changedBy: $command->changedBy ?? (int)CurrentUser::get()->getId(),
					);

					return $result;
				},
			);

			return $transactionValue instanceof AddResult ? $transactionValue : $result;
		}
		catch (Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage()));

			return $result;
		}
	}
}

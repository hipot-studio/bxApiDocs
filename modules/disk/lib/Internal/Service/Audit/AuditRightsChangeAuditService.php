<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Public\Event\Audit\AuditRightsEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use RuntimeException;

final class AuditRightsChangeAuditService
{
	public function __construct(
		private readonly ?AuditDirectRightsSnapshotBuilder $snapshotBuilder = null,
		private readonly ?AuditRightsEventProducer $producer = null,
		private readonly ?AuditAvailabilityService $availabilityService = null,
	)
	{
	}

	public function capture(BaseObject $object): ?array
	{
		$snapshot = null;
		AuditCaptureService::runFailOpen(function () use ($object, &$snapshot): Result {
			$availability = $this->availabilityService
				?? ServiceLocator::getInstance()->get(AuditAvailabilityService::class)
			;
			if (!$availability->canCollect())
			{
				return new Result();
			}
			$snapshot = ($this->snapshotBuilder ?? new AuditDirectRightsSnapshotBuilder())->build($object);

			return new Result();
		});

		return $snapshot;
	}

	public function schedule(
		BaseObject $object,
		?array $before,
		AuditOperationContext $context,
		AuditTransaction $owner,
		?array $legacyViews = null,
	): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($object, $before, $context, $owner, $legacyViews): Result {
			if ($before === null)
			{
				return new Result();
			}
			$after = $this->capture($object);
			if ($after === null)
			{
				return new Result();
			}
			$events = [];
			$builder = new AuditRightsEventProducer(sender: static function (AuditRightsEvent $event) use (&$events): void {
				$events[] = $event;
			});
			$realObject = $object->getRealObject();
			if ($legacyViews === null)
			{
				$builder->publishDirectChanges($realObject, $before, $after, $context);
			}
			else
			{
				$builder->publishChanges($realObject, $legacyViews['before'], $legacyViews['after'], null, $context);
			}
			if ($events !== [])
			{
				$registered = $owner->tryAddAfterConfirmedCommit(function () use ($events): void {
					($this->producer ?? new AuditRightsEventProducer())->publishEvents($events);
				});
				if (!$registered)
				{
					throw new RuntimeException('Audit commit callback cannot be registered.');
				}
			}

			return new Result();
		});
	}
}

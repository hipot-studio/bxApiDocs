<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditObjectSnapshot;
use Bitrix\Disk\Internal\Entity\Audit as Entity;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Throwable;

final class AuditSnapshotBuilder
{
	public function __construct(
		private readonly mixed $pathResolver = null,
	)
	{
	}

	public function buildEvent(AbstractAuditEvent $event): Entity\AuditEvent
	{
		$storage = $event->objectSnapshot === null
			? $this->resolveStorageSnapshot($event->targetObject)
			: [
				'id' => $event->objectSnapshot->storageId,
				'name' => $event->objectSnapshot->storageName,
			];

		return new Entity\AuditEvent(
			source: $event->source,
			operationId: $event->operationId,
			eventCode: $event->eventCode->value,
			occurredAt: $event->occurredAt,
			storageId: $storage['id'],
			storageName: $storage['name'],
		);
	}

	public function buildInitiator(AuditActionContext $context): Entity\AuditInitiator
	{
		return new Entity\AuditInitiator(
			type: $context->initiatorType,
			id: $context->initiatorId,
			name: $context->initiatorName,
			ip: $context->ip,
		);
	}

	public function buildContext(AuditActionContext $context): Entity\AuditContext
	{
		return new Entity\AuditContext(
			type: $context->contextType,
			entityType: $context->contextEntityType,
			entityId: $context->contextEntityId,
			entityName: $context->contextEntityName,
		);
	}

	public function buildTarget(AbstractAuditEvent $event): Entity\AuditTarget
	{
		if ($event->objectSnapshot !== null)
		{
			return new Entity\AuditTarget(
				type: $event->targetType,
				id: $event->targetId,
				name: $event->objectSnapshot->name,
				nameSearch: $event->objectSnapshot->nameSearch,
				path: $event->objectSnapshot->path,
				parentId: $event->objectSnapshot->parentId,
			);
		}

		$object = $event->targetObject;
		$name = $object instanceof BaseObject ? $object->getName() : $event->targetId;

		return new Entity\AuditTarget(
			type: $event->targetType,
			id: $event->targetId,
			name: $name,
			nameSearch: $this->normalizeNameForSearch($name),
			path: $this->resolvePath($object),
			parentId: $object instanceof BaseObject && $object->getParentId() !== null ? (string)$object->getParentId() : null,
		);
	}

	public function buildObjectSnapshot(BaseObject $object): AuditObjectSnapshot
	{
		$storage = $this->resolveStorageSnapshot($object);
		$name = $object->getName();

		return new AuditObjectSnapshot(
			storageId: $storage['id'],
			storageName: $storage['name'],
			name: $name,
			nameSearch: $this->normalizeNameForSearch($name),
			path: $this->resolvePath($object),
			parentId: $object->getParentId() !== null ? (string)$object->getParentId() : null,
		);
	}

	public function buildSubject(?\Bitrix\Disk\Internal\Dto\Audit\AuditSubject $subject): Entity\AuditSubject
	{
		return new Entity\AuditSubject(
			type: $subject?->type,
			id: $subject?->id,
			name: $subject?->id,
		);
	}

	public function normalizeNameForSearch(string $name): string
	{
		$normalized = mb_strtolower(trim($name));
		$normalized = str_replace('ё', 'е', $normalized);
		$normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $normalized) ?? $normalized;

		return trim($normalized);
	}

	private function resolveStorageSnapshot(?BaseObject $object): array
	{
		if (!$object instanceof BaseObject)
		{
			return [
				'id' => null,
				'name' => null,
			];
		}

		$storage = $object->getStorage();

		return [
			'id' => $object->getStorageId() > 0 ? (int)$object->getStorageId() : null,
			'name' => $storage?->getName(),
		];
	}

	private function resolvePath(?BaseObject $object): ?string
	{
		if (!$object instanceof BaseObject)
		{
			return null;
		}

		if (is_callable($this->pathResolver))
		{
			$path = ($this->pathResolver)($object);

			return is_string($path) && $path !== '' ? $path : null;
		}

		try
		{
			$ancestors = ObjectTable::getAncestors(
				$object->getRealObjectId(),
				[
					'select' => [
						'NAME',
						'DEPTH_LEVEL' => 'PATH_PARENT.DEPTH_LEVEL',
					],
				],
			);

			return $this->buildPathFromAncestorRows($ancestors->fetchAll(), $object->getName());
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function buildPathFromAncestorRows(array $ancestorRows, string $objectName): string
	{
		usort(
			$ancestorRows,
			static fn(array $left, array $right): int => (int)($right['DEPTH_LEVEL'] ?? 0) <=> (int)($left['DEPTH_LEVEL'] ?? 0),
		);

		$names = [];
		foreach ($ancestorRows as $ancestorRow)
		{
			$name = (string)($ancestorRow['NAME'] ?? '');
			if ($name !== '')
			{
				$names[] = $name;
			}
		}
		$names[] = $objectName;

		return '/' . implode('/', array_filter($names, static fn(string $name): bool => $name !== ''));
	}
}

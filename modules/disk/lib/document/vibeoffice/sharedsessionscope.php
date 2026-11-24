<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Main\Web\Json;

/** @internal Persisted membership, independent of current admission policy and source liveness. */
final class SharedSessionScope
{
	public static function version(?array $state): int
	{
		return (int)($state['EDIT_SCOPE_VERSION'] ?? 0);
	}

	/** @param DocumentSession[] $sessions @return DocumentSession[] */
	public static function filter(array $sessions, array $state): array
	{
		$version = self::version($state);
		if (!in_array($version, [0, 1], true))
		{
			return [];
		}
		$sessions = array_filter($sessions, static fn(DocumentSession $session): bool => $session->getService() === DocumentService::Vibeoffice
			&& HostDocumentKey::resolve($session) === $state['HOST_DOC_KEY']
			&& (int)$session->getObjectId() === (int)$state['OBJECT_ID']
			&& (int)$session->getVersionId() === (int)$state['VERSION_ID']
			&& $session->getType() === (int)$state['SESSION_TYPE']
			&& ($version === 1 || (int)$session->getExternalLinkId() === (int)$state['EXTERNAL_LINK_ID']),
		);
		if ($version === 0 || $sessions === [])
		{
			return array_values($sessions);
		}
		if ((int)$state['VERSION_ID'] !== 0 || (int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT
			|| (int)$state['EXTERNAL_LINK_ID'] !== 0)
		{
			return [];
		}
		$byId = [];
		foreach ($sessions as $session)
		{
			$byId[(int)$session->getId()] = $session;
		}
		$accepted = [];
		foreach (array_chunk(array_keys($byId), 300) as $ids)
		{
			$rows = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
				->where('HOST_DOC_KEY', $state['HOST_DOC_KEY'])->whereIn('DOCUMENT_SESSION_ID', $ids)->exec()
			;
			while ($row = $rows->fetch())
			{
				$id = (int)$row['DOCUMENT_SESSION_ID'];
				if (self::matchesBinding($byId[$id], $row))
				{
					$accepted[$id] = $byId[$id];
				}
			}
		}

		return array_values($accepted);
	}

	public static function access(array $row): ?array
	{
		try
		{
			$context = Json::decode($row['ACCESS_CONTEXT'] ?? '');
			if (!is_array($context) || !is_string($row['ACCESS_CONTEXT_HASH'] ?? null)
				|| !hash_equals($row['ACCESS_CONTEXT_HASH'], hash('sha256', Json::encode($context))))
			{
				return null;
			}

			return [
				'actorUserId' => $row['ACTOR_USER_ID'] === null ? null : (int)$row['ACTOR_USER_ID'],
				'sourceType' => $row['ACCESS_SOURCE_TYPE'], 'sourceId' => (int)$row['ACCESS_SOURCE_ID'],
				'contextHash' => $row['ACCESS_CONTEXT_HASH'], 'context' => $context,
				'expiresAt' => $row['ACCESS_EXPIRES_AT'],
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public static function matchesBinding(DocumentSession $session, array $row): bool
	{
		$access = self::access($row);
		$context = $session->getContext();
		$vo = DocumentSessionManager::extractVoMetadata($session);

		return $access !== null && $context !== null
			&& (int)$row['DOCUMENT_SESSION_ID'] === (int)$session->getId()
			&& $row['HOST_DOC_KEY'] === HostDocumentKey::resolve($session)
			&& $session->getService() === DocumentService::Vibeoffice
			&& $session->isEdit() && !$session->isVersion()
			&& ($access['context']['schemaVersion'] ?? null) === 1
			&& ($access['context']['objectId'] ?? null) === (int)$session->getObjectId()
			&& $context->getObjectId() === (int)$session->getObjectId()
			&& ($access['context']['attachedObjectId'] ?? null) === $context->getAttachedObjectId()
			&& ($access['context']['versionId'] ?? null) === null
			&& ($access['context']['sourceType'] ?? null) === $access['sourceType']
			&& ($access['context']['sourceId'] ?? null) === $access['sourceId']
			&& in_array($access['sourceType'], ['internal', 'attached', 'external', 'unified'], true)
			&& (int)$context->getExternalLinkId() === (int)$session->getExternalLinkId()
			&& (int)$session->getExternalLinkId()
				=== (in_array($access['sourceType'], ['external', 'unified'], true) ? $access['sourceId'] : 0)
			&& is_int($vo[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] ?? null)
			&& preg_match('/^vp1_[a-f0-9]{32}$/D', $row['PLATFORM_USER_ID'] ?? '') === 1
			&& is_string($row['OPEN_TOKEN'] ?? null)
			&& OpenAttemptState::isLatestToken($vo, $row['OPEN_TOKEN']);
	}
}

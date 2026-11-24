<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\DocumentSessionManager;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
use Bitrix\Disk\Document\Vibeoffice\SharedSessionScope;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class WebhookScopeResolver
{
	public function __construct(
		private readonly DocumentStateRepository $repository = new DocumentStateRepository(),
		private readonly SessionRegistry $registry = new SessionRegistry(),
	)
	{
	}

	public function resolveState(string $hostDocKey): Result
	{
		$state = $this->repository->getByKey($hostDocKey);
		$stable = HostDocumentKey::isStable($hostDocKey);
		if (($state && (int)($state['EDIT_FENCE_STATE'] ?? 0) === 2)
			|| ($state && !in_array(SharedSessionScope::version($state), [0, 1], true))
			|| ($stable && !$state) || ($state && $state['KEY_MODE'] !== (
			$stable ? HostDocumentKey::MODE_STABLE : HostDocumentKey::MODE_LEGACY
		)))
		{
			return (new Result())->addError(new Error('Unknown Vibeoffice document scope.', 'vibeoffice_scope_unknown'));
		}

		return (new Result())->setData(['state' => $state, 'sessions' => [], 'legacy' => !$stable]);
	}

	public function resolve(string $hostDocKey): Result
	{
		$result = $this->resolveState($hostDocKey);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$data = $result->getData();
		$sessions = [];
		foreach ($this->iterateSessions($hostDocKey, $data['state']) as $session)
		{
			$sessions[(int)$session->getId()] = $session;
		}
		$data['sessions'] = array_values($sessions);

		return $result->setData($data);
	}

	/** The caller may stop as soon as a trusted owner is found, including an inactive session. */
	public function iterateSessions(string $hostDocKey, ?array $state): \Generator
	{
		if (SharedSessionScope::version($state) === 1 || HostDocumentKey::isStable($hostDocKey))
		{
			foreach ($this->registry->getSessionIdBatches($hostDocKey) as $ids)
			{
				yield from $this->loadSessions($hostDocKey, $state, $ids);
			}

			return;
		}

		$lastId = 0;
		do
		{
			$rows = DocumentSessionTable::query()->setSelect(['ID'])
				->where('SERVICE', DocumentService::Vibeoffice->value)->where('EXTERNAL_HASH', $hostDocKey)
				->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(100)->fetchAll();
			$ids = array_column($rows, 'ID');
			if ($ids)
			{
				$lastId = (int)end($ids);
				yield from $this->loadSessions($hostDocKey, $state, $ids);
			}
		}
		while (count($rows) === 100);
	}

	/** Includes terminated sessions so a late save still sees its own editor generation. */
	public function iterateGenerationSessions(string $hostDocKey, array $state, string $editorKey, int $rev): \Generator
	{
		foreach ($this->registry->getSessionIdBatches($hostDocKey, $editorKey, $rev) as $ids)
		{
			yield from $this->loadSessions($hostDocKey, $state, $ids);
		}
	}

	public function findOriginatingEditSession(array $state, string $editorKey, int $rev): ?DocumentSession
	{
		if ((int)$state['SESSION_TYPE'] !== DocumentSession::TYPE_EDIT || (int)$state['VERSION_ID'] !== 0)
		{
			return null;
		}

		$hostDocKey = $state['HOST_DOC_KEY'];
		$ownerId = null;
		$origin = null;
		$earliest = null;
		$participant = null;
		$multipleParticipants = false;
		$allBindingsResolved = true;
		$shared = SharedSessionScope::version($state) === 1;
		foreach ($this->registry->getSessionIdBatches($hostDocKey, $editorKey, $rev, confirmedOnly: true) as $ids)
		{
			$sessions = $this->loadSessions($hostDocKey, $state, $ids);
			$allBindingsResolved = $allBindingsResolved && count($sessions) === count($ids);
			foreach ($sessions as $session)
			{
				$candidateOwner = $session->getOwnerId();
				if ($shared)
				{
					if ($candidateOwner <= 0)
					{
						return null;
					}
					$startedAt = $session->getCreateTime()->getTimestamp();
					$order = [$startedAt, (int)$session->getId()];
					if ($startedAt > 0 && ($earliest === null || $order < $earliest))
					{
						$origin = $session;
						$earliest = $order;
					}
					continue;
				}
				if ($candidateOwner <= 0 || ($ownerId !== null && $candidateOwner !== $ownerId))
				{
					return null;
				}
				$ownerId = $candidateOwner;
				// Coauthors inherit OWNER_ID, but their CREATE_TIME records when they joined.
				if ($session->getUserId() !== $ownerId)
				{
					$participant ??= $session;
					$multipleParticipants = $multipleParticipants || $participant->getId() !== $session->getId();
					continue;
				}
				$startedAt = $session->getCreateTime()->getTimestamp();
				$order = [$startedAt, (int)$session->getId()];
				if ($startedAt > 0 && ($earliest === null || $order < $earliest))
				{
					$origin = $session;
					$earliest = $order;
				}
			}
		}

		if ($shared || $origin !== null || $participant === null || $multipleParticipants || !$allBindingsResolved)
		{
			return $origin;
		}

		return $this->hasUnopenedOwner($state, $participant) ? $participant : null;
	}

	private function hasUnopenedOwner(array $state, DocumentSession $participant): bool
	{
		if (
			(int)$state['EXTERNAL_LINK_ID'] !== 0
			|| $participant->getUserId() <= 0
			|| $this->readInternalBindingMetadata($state, $participant) === null
		)
		{
			return false;
		}

		$owners = DocumentSession::getModelList([
			'filter' => [
				'=EXTERNAL_HASH' => $participant->getExternalHash(),
				'=OBJECT_ID' => $state['OBJECT_ID'],
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=VERSION_ID' => null,
				'=STATUS' => DocumentSession::STATUS_ACTIVE,
				'=USER_ID' => $participant->getOwnerId(),
				'=OWNER_ID' => $participant->getOwnerId(),
			],
			'limit' => 2,
		]);
		if (count($owners) !== 1)
		{
			return false;
		}

		$owner = reset($owners);
		$ownerStartedAt = $owner->getCreateTime()->getTimestamp();
		$participantStartedAt = $participant->getCreateTime()->getTimestamp();
		if (
			$ownerStartedAt <= 0
			|| [$ownerStartedAt, $owner->getId()] >= [$participantStartedAt, $participant->getId()]
		)
		{
			return false;
		}

		$vo = $this->readInternalBindingMetadata($state, $owner);
		if ($vo === null)
		{
			return false;
		}

		// A local EDIT row may exist before the slider submits its first open to the platform.
		// The attempt floor and confirmed metadata survive pruning of the registry rows.
		$openMetadata = [
			'session_id', 'editor_key', 'rev',
			DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY,
			DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY,
			DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY,
			DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY,
			DocumentSessionManager::CONTEXT_VO_OPENED_CONTENT_VERSION_KEY,
			DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY,
		];
		if (array_intersect_key($vo, array_flip($openMetadata)) !== [])
		{
			return false;
		}

		return !PlatformSessionTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_SESSION_ID', $owner->getId())
			->setLimit(1)
			->fetch()
		;
	}

	private function readInternalBindingMetadata(array $state, DocumentSession $session): ?array
	{
		$context = json_decode($session->getContextRaw() ?? '', true);
		$vo = $context[DocumentSessionManager::CONTEXT_VO_KEY] ?? null;
		if (
			($context['objectId'] ?? null) !== (int)$state['OBJECT_ID']
			|| ($context['externalLinkId'] ?? null) !== null
			|| (int)$session->getExternalLinkId() !== 0
			|| !is_array($vo) || ($vo[HostDocumentKey::CONTEXT_KEY] ?? null) !== $state['HOST_DOC_KEY']
		)
		{
			return null;
		}

		return $vo;
	}

	/** The generation comes from the classified event; it does not authorize finalization. */
	public function resolveRecipients(string $hostDocKey, string $editorKey, int $rev): Result
	{
		$result = $this->resolveState($hostDocKey);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$data = $result->getData();
		$ids = $this->registry->getRecipientSessionIds($hostDocKey, $editorKey, $rev);
		$sessions = [];
		foreach (array_chunk($ids, 100) as $batch)
		{
			array_push($sessions, ...$this->loadSessions($hostDocKey, $data['state'], $batch));
		}
		$data['sessions'] = $sessions;

		return $result->setData($data);
	}

	private function loadSessions(string $hostDocKey, ?array $state, array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}
		$sessions = DocumentSession::getModelList([
			'filter' => ['=SERVICE' => DocumentService::Vibeoffice->value, '@ID' => $ids],
			'order' => ['ID' => 'ASC'],
		]);

		return $state ? SharedSessionScope::filter($sessions, $state) : array_values(array_filter(
			$sessions, static fn(DocumentSession $session): bool => HostDocumentKey::resolve($session) === $hostDocKey,
		));
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\Document\Vibeoffice\SessionRegistry;
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
		if (($stable && !$state) || ($state && $state['KEY_MODE'] !== (
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
		if (HostDocumentKey::isStable($hostDocKey))
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

		return array_values(array_filter($sessions, static function (DocumentSession $session) use ($hostDocKey, $state): bool {
			return HostDocumentKey::resolve($session) === $hostDocKey && (!$state || (
				(int)$session->getObjectId() === (int)$state['OBJECT_ID']
				&& (int)$session->getVersionId() === (int)$state['VERSION_ID']
				&& $session->getType() === (int)$state['SESSION_TYPE']
				&& (int)$session->getExternalLinkId() === (int)$state['EXTERNAL_LINK_ID']
			));
		}));
	}
}

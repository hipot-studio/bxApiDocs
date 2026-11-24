<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformNamespace;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

/** @internal */
final class SavedEditSessionSelector
{
	public const RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC = 60;

	public function __construct(private readonly ?PlatformNamespace $platformNamespace = null)
	{
	}

	/** @return iterable<DocumentSession> */
	public function iterate(int $realObjectId): iterable
	{
		$sessions = DocumentSession::getList([
			'select' => [
				'ID', 'EXTERNAL_HASH', 'STATUS', 'CONTEXT', 'CREATE_TIME', 'OBJECT_ID',
				'EXTERNAL_LINK_ID', 'TYPE', 'SERVICE', 'VERSION_ID',
			],
			'filter' => [
				'=OBJECT_ID' => $realObjectId,
				'=VERSION_ID' => null,
				'=TYPE' => DocumentSession::TYPE_EDIT,
				'=SERVICE' => DocumentService::Vibeoffice->value,
				[
					'LOGIC' => 'OR',
					['=STATUS' => DocumentSession::STATUS_ACTIVE],
					[
						'=STATUS' => DocumentSession::STATUS_NON_ACTIVE,
						'>=CREATE_TIME' => DateTime::createFromTimestamp(
							time() - self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC,
						),
					],
				],
			],
			'order' => ['ID' => 'DESC'],
		]);
		$states = [];
		$batch = [];
		while ($row = $sessions->fetch())
		{
			$session = DocumentSession::buildFromArray($row);
			if (!$session->isActive() && (new DateTime())->getTimestamp() - $session->getCreateTime()->getTimestamp()
				> self::RECENT_NON_ACTIVE_SESSION_MAX_AGE_SEC)
			{
				continue;
			}
			$batch[] = $session;
			if (count($batch) === 100)
			{
				yield from $this->filterBatch($batch, $states);
				$batch = [];
			}
		}
		yield from $this->filterBatch($batch, $states);
	}

	private function filterBatch(array $batch, array &$states): array
	{
		$namespace = $this->platformNamespace ?? new PlatformNamespace(
			ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration'),
		);
		$groups = $accepted = [];
		foreach ($batch as $session)
		{
			$groups[HostDocumentKey::resolve($session)][] = $session;
		}
		foreach ($groups as $key => $sessions)
		{
			if (!array_key_exists($key, $states))
			{
				$states[$key] = (new DocumentStateRepository())->getByKey($key);
			}
			$state = $states[$key];
			if ($state ? !$namespace->isCurrent((string)$state['NAMESPACE']) : $namespace->hasSwitched())
			{
				continue;
			}
			$members = $state && SharedSessionScope::version($state) !== 0
				? SharedSessionScope::filter($sessions, $state) : $sessions;
			// A revoke ACK stops transports; recent closed sessions still carry unapplied content.
			array_push($accepted, ...$members);
		}

		$acceptedIds = array_fill_keys(array_map(static fn($session): int => (int)$session->getId(), $accepted), true);

		return array_values(array_filter($batch, static fn($session): bool => isset($acceptedIds[$session->getId()])));
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A distinct historical form and all of its document binding intervals. */
final readonly class FormUsage
{
	public array $versionIds;
	public array $intervals;

	public function __construct(
		public int $sourceId,
		public int $formId,
		public int $documentId,
		public string $formName,
		public bool $deleted,
		public ?string $formUrl,
		public string $sourceState,
		public int $sourceRevision,
		array $versionIds,
		array $intervals,
	)
	{
		foreach ([$sourceId, $formId, $documentId, $sourceRevision] as $id) { self::id($id); }
		if (!in_array($sourceState, ['active', 'inactive', 'deleted'], true)
			|| trim($formName) === '' || ($deleted && $formUrl !== null)
			|| ($formUrl !== null && (!str_starts_with($formUrl, '/') || str_starts_with($formUrl, '//')))
			|| !array_is_list($versionIds) || !array_is_list($intervals))
		{
			throw new InvalidArgumentException('Invalid form usage.');
		}
		$ids = [];
		foreach ($versionIds as $id)
		{
			self::id($id);
			if (isset($ids[$id])) { throw new InvalidArgumentException('Invalid version list.'); }
			$ids[$id] = $id;
		}
		$this->versionIds = array_values($ids);
		$history = [];
		$keys = ['versionId', 'revision', 'checked', 'required', 'startsAt', 'endsAt', 'formNameSnapshot'];
		foreach ($intervals as $interval)
		{
			if (!is_array($interval) || count($interval) !== count($keys) || array_diff($keys, array_keys($interval)))
			{
				throw new InvalidArgumentException('Invalid usage interval.');
			}
			self::id($interval['versionId']); self::id($interval['revision']);
			if (!isset($ids[$interval['versionId']]) || !is_bool($interval['checked']) || !is_bool($interval['required'])
				|| !$interval['startsAt'] instanceof DateTimeImmutable
				|| ($interval['endsAt'] !== null && (!$interval['endsAt'] instanceof DateTimeImmutable
					|| $interval['endsAt'] < $interval['startsAt']))
				|| !is_string($interval['formNameSnapshot']) || trim($interval['formNameSnapshot']) === '')
			{
				throw new InvalidArgumentException('Invalid usage interval.');
			}
			$history[] = ['versionId' => $interval['versionId'], 'revision' => $interval['revision'],
				'checked' => $interval['checked'], 'required' => $interval['required'],
				'startsAt' => $interval['startsAt']->setTimezone(new DateTimeZone('UTC')),
				'endsAt' => $interval['endsAt']?->setTimezone(new DateTimeZone('UTC')),
				'formNameSnapshot' => $interval['formNameSnapshot']];
		}
		$this->intervals = $history;
	}

	private static function id(mixed $id): void
	{
		if (!is_int($id) || $id <= 0 || $id > 2147483647) { throw new InvalidArgumentException('Invalid usage identity.'); }
	}
}

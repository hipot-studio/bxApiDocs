<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSource;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceAddress;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRecord;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRefusal;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceRefusedException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceRepository;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceWriteRepository;

/**
 * Takes the sources of the end-to-end analytics to the archive and back. Only the activity of the record is
 * changed: its fields, traces and expenses stay. A repeated call is a success that writes and rebuilds nothing.
 *
 * Unlike the UI, a return is refused when another record of the code is active, so no second active record of
 * a code is made, and the call tracker is rebuilt after it too. As in the UI, the return is not limited by the
 * tariff or the region.
 *
 * Organic traffic and email marketing stay out of the archive: legacy would save another record of email
 * marketing as soon as it read the sources.
 *
 * @internal
 */
final class SourceArchiver
{
	private readonly SourceCatalog $catalog;
	private readonly SourceReader $reader;

	public function __construct(
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly TrackingEnvironment $environment = new TrackingEnvironment(),
		private readonly SourceWriteCommit $commit = new SourceWriteCommit(),
		private readonly SourceRepository $sources = new SourceRepository(),
		private readonly SourceWriteRepository $writer = new SourceWriteRepository(),
	)
	{
		$this->catalog = new SourceCatalog($environment);
		$this->reader = new SourceReader(catalog: $this->catalog);
	}

	/**
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingSourceNotFoundException
	 * @throws TrackingSourceRefusedException NotConfigurable, RegionUnavailable, TariffRestricted
	 */
	public function archive(int $userId, SourceAddress $address): void
	{
		$this->access->assertCanModify($userId);

		['record' => $record, 'builtIn' => $builtIn] = $this->locateArchivable($address);
		if (!$record->isActive)
		{
			return;
		}

		if ($this->catalog->isRegionRestricted($record->code))
		{
			throw new TrackingSourceRefusedException(SourceRefusal::RegionUnavailable, $record->id);
		}
		if ($builtIn === null && !$this->environment->hasOwnSourcesTariff())
		{
			throw new TrackingSourceRefusedException(SourceRefusal::TariffRestricted, $record->id);
		}

		$this->commit->run(null, fn() => $this->writer->setActive($record->id, false));
	}

	/**
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingSourceNotFoundException
	 * @throws TrackingSourceRefusedException NotConfigurable, ActiveExists, Busy
	 */
	public function unarchive(int $userId, SourceAddress $address): void
	{
		$this->access->assertCanModify($userId);

		$record = $this->locateArchivable($address)['record'];
		if ($record->isActive)
		{
			return;
		}

		// Own sources have no code to collide on; the stored code counts even when it is not available now.
		$lockCode = $record->code !== '' ? $record->code : null;
		$this->commit->run($lockCode, function () use ($record): void
		{
			if ($record->code !== '' && $this->hasOtherActiveRecord($record))
			{
				throw new TrackingSourceRefusedException(SourceRefusal::ActiveExists, $record->id);
			}

			$this->writer->setActive($record->id, true);
		});
	}

	/**
	 * @return array{record: SourceRecord, builtIn: BuiltInSource|null} builtIn is null for an own source and for
	 * a record of a code not available on the portal
	 * @throws TrackingSourceNotFoundException
	 * @throws TrackingSourceRefusedException NotConfigurable - there is no record, or the source is described by
	 * the product and has nothing to archive
	 */
	private function locateArchivable(SourceAddress $address): array
	{
		['record' => $record, 'builtIn' => $builtIn] = $this->reader->locate($address);

		if ($record === null || ($builtIn !== null && !$builtIn->code->isConfigurable()))
		{
			throw new TrackingSourceRefusedException(SourceRefusal::NotConfigurable, $record?->id);
		}

		return ['record' => $record, 'builtIn' => $builtIn];
	}

	private function hasOtherActiveRecord(SourceRecord $record): bool
	{
		foreach ($this->sources->listRecordsByCode($record->code) as $codeRecord)
		{
			if ($codeRecord->isActive && $codeRecord->id !== $record->id)
			{
				return true;
			}
		}

		return false;
	}
}

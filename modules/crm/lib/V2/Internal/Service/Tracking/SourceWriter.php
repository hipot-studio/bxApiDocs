<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Tracking\Internals\SourceFieldTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\BuiltInSource;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceAddress;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceChanges;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRecord;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRefusal;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceRefusedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingValidationException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceWriteRepository;

/**
 * Adds the own sources of the end-to-end analytics and sets the sources up, the way the source editor of the
 * UI saves them, except where the UI writes wrong data or loses it:
 * - only the passed values are written, the saved domains of a built-in source are not erased;
 * - a code names its existing record, the first save creates one record under the lock of the code, a code
 *   whose record is archived is refused instead of getting a duplicate;
 * - organic traffic, email marketing and 1C without a record are refused instead of being ignored;
 * - the color of a built-in source and the domains the product defines are refused instead of being dropped;
 * - a list of more than a hundred values is refused, the UI takes any number;
 * - the call tracker of the sites is not rebuilt when only the name or the color of a record changes: the
 *   tracker carries neither.
 *
 * A record of a built-in code that is not available on the portal is an own source here, as in the lists:
 * the tariff applies, its color and domains are written, its stored code is kept.
 *
 * The refused field is named as in the REST fields of a source.
 *
 * @internal
 */
final class SourceWriter
{
	private const MAX_LENGTH = 255;
	private const MAX_VALUES = 100;
	private const COLOR_PATTERN = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

	private readonly SourceCatalog $catalog;
	private readonly SourceReader $reader;

	public function __construct(
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly TrackingEnvironment $environment = new TrackingEnvironment(),
		private readonly SourceWriteCommit $commit = new SourceWriteCommit(),
		private readonly SourceWriteRepository $writer = new SourceWriteRepository(),
	)
	{
		$this->catalog = new SourceCatalog($environment);
		$this->reader = new SourceReader(catalog: $this->catalog);
	}

	/**
	 * @return int the id of the new own source
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingSourceRefusedException TariffRestricted
	 * @throws TrackingValidationException
	 */
	public function add(int $userId, SourceChanges $changes): int
	{
		$this->access->assertCanModify($userId);
		$this->assertOwnSourcesAllowed(null);
		if ($changes->name === null)
		{
			throw new TrackingValidationException('name', 'CRM_V2_TRACKING_SOURCE_NAME_REQUIRED');
		}
		$this->assertValid($changes, null);

		return $this->commit->run(null, function () use ($changes, $userId): int
		{
			$sourceId = $this->writer->create('', (string)$changes->name, $changes->color ?? '', $userId);
			$this->writeFields($sourceId, $changes);

			return $sourceId;
		});
	}

	/**
	 * Changes the passed values of the source. A built-in code without a record gets its record with the name of
	 * the catalog, unless another name is passed. An archived record is updated by its id.
	 *
	 * @return int the id of the record written
	 * @throws TrackingAccessDeniedException
	 * @throws TrackingSourceNotFoundException
	 * @throws TrackingSourceRefusedException
	 * @throws TrackingValidationException
	 */
	public function update(int $userId, SourceAddress $address, SourceChanges $changes): int
	{
		$this->access->assertCanModify($userId);

		['record' => $record, 'builtIn' => $builtIn] = $this->reader->locate($address);
		$this->assertCanChange($address, $record, $builtIn);
		$this->assertValid($changes, $builtIn);

		if ($record !== null)
		{
			return $this->commit->run(
				null,
				fn(): int => $this->write($record->id, $changes, $userId),
				$changes->changesRecognition(),
			);
		}

		return $this->saveFirst(
			$address,
			$builtIn ?? throw new TrackingSourceNotFoundException($address),
			$changes,
			$userId,
		);
	}

	/**
	 * A parallel request may save the code first: the code is located again under its lock, and its record is
	 * created only when there is still none.
	 *
	 * @throws TrackingSourceRefusedException Busy, or Archived when the record of the parallel request is
	 * archived already
	 */
	private function saveFirst(
		SourceAddress $address,
		BuiltInSource $builtIn,
		SourceChanges $changes,
		int $userId,
	): int
	{
		return $this->commit->run(
			$builtIn->code->value,
			function () use ($address, $builtIn, $changes, $userId): int
			{
				$record = $this->reader->locate($address)['record'];
				if ($record === null)
				{
					return $this->create($builtIn, $changes, $userId);
				}
				if (!$record->isActive)
				{
					throw new TrackingSourceRefusedException(SourceRefusal::Archived, $record->id);
				}

				return $this->write($record->id, $changes, $userId);
			},
		);
	}

	/**
	 * @throws TrackingSourceRefusedException
	 */
	private function assertCanChange(SourceAddress $address, ?SourceRecord $record, ?BuiltInSource $builtIn): void
	{
		$sourceId = $record?->id;
		if ($builtIn !== null)
		{
			if (!$builtIn->code->isConfigurable() || ($record === null && $builtIn->showOnlyExisting))
			{
				throw new TrackingSourceRefusedException(SourceRefusal::NotConfigurable, $sourceId);
			}

			if ($address->code !== null && $record !== null && !$record->isActive)
			{
				throw new TrackingSourceRefusedException(SourceRefusal::Archived, $sourceId);
			}
		}

		$code = $record !== null ? $record->code : (string)$builtIn?->code->value;
		if ($this->catalog->isRegionRestricted($code))
		{
			throw new TrackingSourceRefusedException(SourceRefusal::RegionUnavailable, $sourceId);
		}

		if ($builtIn === null)
		{
			$this->assertOwnSourcesAllowed($sourceId);
		}
	}

	/**
	 * @throws TrackingSourceRefusedException
	 */
	private function assertOwnSourcesAllowed(?int $sourceId): void
	{
		if (!$this->environment->hasOwnSourcesTariff())
		{
			throw new TrackingSourceRefusedException(SourceRefusal::TariffRestricted, $sourceId);
		}
	}

	/**
	 * @param BuiltInSource|null $builtIn the built-in source being changed; null - an own source
	 * @throws TrackingValidationException
	 */
	private function assertValid(SourceChanges $changes, ?BuiltInSource $builtIn): void
	{
		if ($changes->name === '')
		{
			throw new TrackingValidationException('name', 'CRM_V2_TRACKING_SOURCE_NAME_REQUIRED');
		}
		if ($changes->name !== null && mb_strlen($changes->name) > self::MAX_LENGTH)
		{
			throw new TrackingValidationException(
				'name',
				'CRM_V2_TRACKING_SOURCE_NAME_TOO_LONG',
				['#MAX#' => self::MAX_LENGTH],
			);
		}

		if ($changes->color !== null)
		{
			if ($builtIn !== null)
			{
				throw new TrackingValidationException('color', 'CRM_V2_TRACKING_SOURCE_COLOR_OF_BUILT_IN');
			}
			if (preg_match(self::COLOR_PATTERN, $changes->color) !== 1)
			{
				throw new TrackingValidationException('color', 'CRM_V2_TRACKING_SOURCE_COLOR_INVALID');
			}
		}

		if ($changes->refDomains !== null)
		{
			if ($builtIn?->hasPredefinedDomains())
			{
				throw new TrackingValidationException('refDomains', 'CRM_V2_TRACKING_SOURCE_REF_DOMAIN_PREDEFINED');
			}
			$this->assertValuesFit(
				'refDomains',
				$changes->refDomains,
				'CRM_V2_TRACKING_SOURCE_REF_DOMAIN_TOO_MANY',
				'CRM_V2_TRACKING_SOURCE_REF_DOMAIN_TOO_LONG',
			);
		}

		if ($changes->utmSources !== null)
		{
			$this->assertValuesFit(
				'utmSources',
				$changes->utmSources,
				'CRM_V2_TRACKING_SOURCE_UTM_SOURCE_TOO_MANY',
				'CRM_V2_TRACKING_SOURCE_UTM_SOURCE_TOO_LONG',
			);
		}
	}

	/**
	 * @param list<string> $values trimmed, without the empty ones
	 * @throws TrackingValidationException
	 */
	private function assertValuesFit(string $field, array $values, string $tooManyPhrase, string $tooLongPhrase): void
	{
		if (count($values) > self::MAX_VALUES)
		{
			throw new TrackingValidationException($field, $tooManyPhrase, ['#MAX#' => self::MAX_VALUES]);
		}

		foreach ($values as $value)
		{
			if (mb_strlen($value) > self::MAX_LENGTH)
			{
				throw new TrackingValidationException($field, $tooLongPhrase, ['#MAX#' => self::MAX_LENGTH]);
			}
		}
	}

	/**
	 * The color of a built-in source belongs to the catalog and is not stored, as in the UI.
	 */
	private function create(BuiltInSource $builtIn, SourceChanges $changes, int $userId): int
	{
		$sourceId = $this->writer->create(
			$builtIn->code->value,
			$changes->name ?? $builtIn->defaultName,
			'',
			$userId,
		);
		$this->writeFields($sourceId, $changes);

		return $sourceId;
	}

	private function write(int $sourceId, SourceChanges $changes, int $userId): int
	{
		$values = [];
		if ($changes->name !== null)
		{
			$values['name'] = $changes->name;
		}
		if ($changes->color !== null)
		{
			$values['color'] = $changes->color;
		}

		$this->writer->update($sourceId, $values, $userId);
		$this->writeFields($sourceId, $changes);

		return $sourceId;
	}

	private function writeFields(int $sourceId, SourceChanges $changes): void
	{
		if ($changes->utmSources !== null)
		{
			$this->writer->replaceField($sourceId, SourceFieldTable::FIELD_UTM_SOURCE, $changes->utmSources);
		}
		if ($changes->refDomains !== null)
		{
			$this->writer->replaceField($sourceId, SourceFieldTable::FIELD_REF_DOMAIN, $changes->refDomains);
		}
	}
}

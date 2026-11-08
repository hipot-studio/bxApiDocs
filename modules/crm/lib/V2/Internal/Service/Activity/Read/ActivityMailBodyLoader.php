<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Read;

use Bitrix\Crm\Activity\Entity\ActMailBodyBindTable;
use Bitrix\Crm\Activity\MailBodyTable;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Update\Activity\CompressMailStepper;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Main\Config\Option;
use Closure;
use CCrmOwnerType;

/**
 * Puts the text of a compressed mail back where a reader looks for it: into the description of the activity.
 *
 * A compressed mail keeps a short plain preview in the column of the description and the text itself in the
 * table of mail bodies. Legacy REST answers the text, not the preview
 * (`CCrmActivityRestProxy::externalizeFields()` -> {@see Email::uncompressActivityDescription()}).
 *
 * The body is found the way legacy finds it: while the stepper converting the mails of the portal still runs,
 * an activity of the old format names its body by its associated entity; once converted, the body is bound to
 * it. Where no body answers, legacy goes on to restore the text from the mailbox and marks the activity as
 * checked - a write and a request to the mail module, which a read does not make, keeping the preview.
 *
 * At most two queries per set, and none for a set without a compressed mail.
 *
 * @internal
 */
final class ActivityMailBodyLoader
{
	private readonly Closure $isCompressionInProgress;

	/**
	 * @param Closure|null $isCompressionInProgress `function (): bool` - whether the stepper converting the mails
	 *        of the portal still runs; the option it keeps by default.
	 */
	public function __construct(?Closure $isCompressionInProgress = null)
	{
		$this->isCompressionInProgress = $isCompressionInProgress ?? static fn(): bool => Option::get(
			'crm',
			CompressMailStepper::COMPRESS_IN_PROGRESS_OPTION_NAME,
			'N',
		) === 'Y';
	}

	public function fill(Activity ...$activities): void
	{
		$compressed = [];
		foreach ($activities as $activity)
		{
			$activityId = (int)$activity->getId();
			if ($activityId > 0 && $activity->getProviderTypeId() === Email::TYPE_EMAIL_COMPRESSED)
			{
				$compressed[$activityId] = $activity;
			}
		}

		if ($compressed === [])
		{
			return;
		}

		[$boundBodyIds, $associatedBodyIds] = $this->bodyIdsOf($compressed);
		$bodies = self::readBodies(array_merge(array_values($boundBodyIds), array_values($associatedBodyIds)));

		foreach ($compressed as $activityId => $activity)
		{
			$body = self::bodyFor($activityId, $boundBodyIds, $associatedBodyIds, $bodies);
			if ($body !== null)
			{
				$activity->internalSet(Activity::description, $body);
			}
		}
	}

	/**
	 * @param array<int, int> $boundBodyIds
	 * @param array<int, int> $associatedBodyIds
	 * @param array<int, string> $bodies
	 */
	private static function bodyFor(
		int $activityId,
		array $boundBodyIds,
		array $associatedBodyIds,
		array $bodies,
	): ?string
	{
		if (isset($associatedBodyIds[$activityId]))
		{
			return $bodies[$associatedBodyIds[$activityId]] ?? null;
		}

		$body = $bodies[$boundBodyIds[$activityId] ?? 0] ?? '';

		return $body === '' ? null : $body;
	}

	/**
	 * @param array<int, Activity> $compressed
	 * @return array{0: array<int, int>, 1: array<int, int>} the bound bodies and the bodies named by the associated
	 *         entity, both by activity id
	 */
	private function bodyIdsOf(array $compressed): array
	{
		$associatedBodyIds = [];
		if (($this->isCompressionInProgress)())
		{
			foreach ($compressed as $activityId => $activity)
			{
				$associatedEntityId = (int)$activity->getAssociatedEntityId();
				if ($associatedEntityId > 0)
				{
					$associatedBodyIds[$activityId] = $associatedEntityId;
				}
			}
		}

		$boundActivityIds = array_keys(array_diff_key($compressed, $associatedBodyIds));

		return [self::readBoundBodyIds($boundActivityIds), $associatedBodyIds];
	}

	/**
	 * The first binding of an activity names its body, as the one legacy takes.
	 *
	 * @param int[] $activityIds
	 * @return array<int, int> body id by activity id
	 */
	private static function readBoundBodyIds(array $activityIds): array
	{
		if ($activityIds === [])
		{
			return [];
		}

		$rows =
			ActMailBodyBindTable::query()
				->setSelect(['OWNER_ID', 'BODY_ID'])
				->where('OWNER_TYPE_ID', CCrmOwnerType::Activity)
				->whereIn('OWNER_ID', $activityIds)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$bodyIds = [];
		foreach ($rows as $row)
		{
			$bodyIds[(int)$row['OWNER_ID']] ??= (int)$row['BODY_ID'];
		}

		return $bodyIds;
	}

	/**
	 * @param int[] $bodyIds
	 * @return array<int, string> the uncompressed text by body id
	 */
	private static function readBodies(array $bodyIds): array
	{
		$bodyIds = array_values(array_unique(array_filter($bodyIds, static fn(int $bodyId): bool => $bodyId > 0)));
		if ($bodyIds === [])
		{
			return [];
		}

		$rows =
			MailBodyTable::query()
				->setSelect(['ID', 'BODY'])
				->whereIn('ID', $bodyIds)
				->fetchAll()
		;

		$bodies = [];
		foreach ($rows as $row)
		{
			$bodies[(int)$row['ID']] = (string)$row['BODY'];
		}

		return $bodies;
	}
}

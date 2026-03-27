<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\LimitEncounter\DocumentEditSession\Notification;

use Bitrix\Disk\Internal\Command\LimitEncounter\SendToDomainStoreCommand;
use Bitrix\Disk\Internal\Command\Notification\NotifyThroughImSimpleSystemNotificationCommand;
use Bitrix\Disk\Internal\Localization\LimitEncounterMessages;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;

class ThresholdReachedCommandFactory
{
	public function __construct(
		private readonly Environment $environment,
	)
	{
	}

	/**
	 * @param int $thresholdPosition
	 * @param int $thresholdValue
	 * @return AbstractCommand[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function create(int $thresholdPosition, int $thresholdValue): array
	{
		return [
			new NotifyThroughImSimpleSystemNotificationCommand(
				title: fn (?string $languageId = null) => LimitEncounterMessages::adminPortalNotificationTitle($languageId),
				message: fn (?string $languageId = null) => LimitEncounterMessages::adminPortalNotificationDocumentEditSessionThresholdReached(
					thresholdValue: $thresholdValue,
					thresholdIndex: $thresholdPosition,
					isCloud: $this->environment->isCloudPortal(),
					languageId: $languageId,
				),
				recipients: $this->getPortalAdministratorIds(),
			),
			new SendToDomainStoreCommand(
				domain: $this->environment->getDomain(),
				action: 'document_edit_session_limit_encounter_threshold_reached',
			),
		];
	}

	/**
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getPortalAdministratorIds(): array
	{
		$portalAdministrators = [];

		$users = UserTable::query()
			->setSelect(['ID'])
			->setDistinct()
			->setFilter([
				'GROUPS.GROUP_ID' => 1,
				'=ACTIVE' => 'Y',
			])
			->exec()
		;

		while ($user = $users->fetch())
		{
			$portalAdministrators[] = (int)$user['ID'];
		}

		return $portalAdministrators;
	}
}

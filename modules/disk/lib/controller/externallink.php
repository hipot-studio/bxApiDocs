<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckExternalLinkSettingsPermission;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Type\DateTime;

final class ExternalLink extends Engine\Controller
{
	public function getPrimaryAutoWiredParameter(): ExactParameter
	{
		return new ExactParameter(Disk\ExternalLink::class, 'externalLink', function($className, $id){
			return Disk\ExternalLink::loadById($id);
		});
	}

	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new CheckExternalLinkSettingsPermission(),
		];
	}

	public function allowEditDocumentAction(Disk\ExternalLink $externalLink): void
	{
		if ($externalLink->availableEdit())
		{
			$storage = $externalLink->getObject()->getStorage();
			$securityContext = $storage->getSecurityContext($this->getCurrentUser());

			if ($externalLink->getObject()->canUpdate($securityContext))
			{
				$externalLink->changeAccessRight(Disk\ExternalLink::ACCESS_RIGHT_EDIT);
			}
		}
	}

	public function disallowEditDocumentAction(Disk\ExternalLink $externalLink): void
	{
		if ($externalLink->availableEdit())
		{
			$externalLink->changeAccessRight(Disk\ExternalLink::ACCESS_RIGHT_VIEW);
		}
	}

	public function setPasswordAction(Disk\ExternalLink $externalLink, $newPassword): void
	{
		$externalLink->changePassword($newPassword);
	}

	public function setDeathTimeAction(Disk\ExternalLink $externalLink, $deathTime): array
	{
		$deathTime = (int)$deathTime;
		$deathTime = DateTime::createFromTimestamp($deathTime);

		$externalLink->changeDeathTime($deathTime);

		return [
			'externalLink' => [
				'id' => $externalLink->getId(),
				'hasDeathTime' => $externalLink->hasDeathTime(),
				'deathTime' => $externalLink->getDeathTime(),
				'deathTimeTimestamp' => $externalLink->hasDeathTime() ? $externalLink->getDeathTime()->getTimestamp() : null,
			],
		];
	}

	public function revokeDeathTimeAction(Disk\ExternalLink $externalLink): void
	{
		$externalLink->revokeDeathTime();
	}

	public function revokePasswordAction(Disk\ExternalLink $externalLink): void
	{
		$externalLink->revokePassword();
	}
}

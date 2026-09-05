<?php

namespace Bitrix\DiskMobile\Controller;

use Bitrix\Disk\Driver;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Disk;
use Bitrix\Main\Error;
use Bitrix\Main\Web\Uri;

class ExternalLink extends BaseFileList
{
	private const ERROR_EXTERNAL_LINK_NOT_FOUND = 'DISKMOBILE_EXTERNAL_LINK_NOT_FOUND';
	private const ERROR_EXTERNAL_LINK_OBJECT_NOT_FOUND = 'DISKMOBILE_EXTERNAL_LINK_OBJECT_NOT_FOUND';

	public function configureActions(): array
	{
		return [
			'updateSecuritySettings' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'generate' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function generateAction(
		int $objectId,
		?string $newPassword = null,
		?bool $allowEditDocument = null,
		?int $deathTime = null,
	)
	{
		$externalLink = $this->forward(
			\Bitrix\Disk\Controller\CommonActions::class,
			'generateExternalLink',
		)['externalLink'];

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$externalLinkId = $externalLink['id'];

		if ($newPassword !== null)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'setPassword',
				[
					'id' => $externalLinkId,
					'newPassword' => $newPassword,
				],
			);
		}

		if ($allowEditDocument)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'allowEditDocument',
				[
					'id' => $externalLinkId,
				],
			);
		}

		if ($deathTime)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'setDeathTime',
				[
					'id' => $externalLinkId,
					'deathTime' => $deathTime,
				],
			);
		}

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$extLink = $this->loadExternalLink($externalLinkId);
		if (!$extLink)
		{
			$this->addError(new Error(
				'Could not find external link.',
				self::ERROR_EXTERNAL_LINK_NOT_FOUND,
				['externalLinkId' => $externalLinkId],
			));

			return null;
		}

		return $this->parseExternalLinkObject($extLink);
	}

	protected function loadExternalLink(int $externalLinkId): ?Disk\ExternalLink
	{
		return Disk\ExternalLink::loadById($externalLinkId);
	}

	private function parseExternalLinkObject(Disk\ExternalLink $extLink): ?array
	{
		$object = $extLink->getObject();
		if (!$object instanceof Disk\BaseObject)
		{
			$this->addError(new Error(
				'Could not find object for external link.',
				self::ERROR_EXTERNAL_LINK_OBJECT_NOT_FOUND,
				['externalLinkId' => $extLink->getId()],
			));

			return null;
		}

		$driver = Driver::getInstance();
		$link = new Uri($driver->getUrlManager()->getPublicExternalLink(
			object: $object,
			hash: $extLink->getHash(),
		));

		$canEditDocument = null;
		$availableEdit = $extLink->availableEdit();
		if ($availableEdit)
		{
			$canEditDocument = $extLink->getAccessRight() === $extLink::ACCESS_RIGHT_EDIT;
		}

		return [
			'externalLink' => [
				'id' => $extLink->getId(),
				'objectId' => $extLink->getObjectId(),
				'link' => $link,
				'hasPassword' => $extLink->hasPassword(),
				'hasDeathTime' => $extLink->hasDeathTime(),
				'availableEdit' => $availableEdit,
				'canEditDocument' => $canEditDocument,
				'deathTime' => $extLink->getDeathTime(),
				'deathTimeTimestamp' => $extLink->hasDeathTime() ? $extLink->getDeathTime()->getTimestamp() : null,
			],
		];
	}

	/** @see Disk\Controller\externallink::setPasswordAction() */
	/** @see Disk\Controller\externallink::revokePasswordAction() */
	/** @see Disk\Controller\externallink::allowEditDocumentAction() */
	/** @see Disk\Controller\externallink::disallowEditDocumentAction() */
	/** @see Disk\Controller\externallink::setDeathTimeAction() */
	/** @see Disk\Controller\externallink::revokeDeathTimeAction() */
	public function updateSecuritySettingsAction(
		int $id,
		?string $newPassword = null,
		bool $disablePassword = false,
		?bool $allowEditDocument = null,
		?int $deathTime = null,
		bool $disableDeathTime = false,
	)
	{
		if (!$disablePassword && $newPassword)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'setPassword',
			);
		}

		if ($disablePassword)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'revokePassword',
			);
		}

		if ($allowEditDocument)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'allowEditDocument',
			);
		}
		else
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'disallowEditDocument',
			);
		}

		if (!$disableDeathTime && $deathTime)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'setDeathTime',
			);
		}

		if($disableDeathTime)
		{
			$this->forward(
				\Bitrix\Disk\Controller\ExternalLink::class,
				'revokeDeathTime',
			);
		}
	}
}

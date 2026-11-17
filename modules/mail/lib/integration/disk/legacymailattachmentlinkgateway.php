<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\ExternalLink;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Gateway that creates the link of a large attachment the way the mail module did before the mail
 * attachment link commands appeared in Disk.
 *
 * Temporary: the class exists only to let the mail module go out earlier than disk 26.1200.0, and is
 * removed together with the branch of {@see LargeAttachmentStorageFactory} that chooses it, once that
 * update of Disk becomes mandatory.
 */
final class LegacyMailAttachmentLinkGateway implements MailAttachmentLinkGateway
{
	/**
	 * The previous way created a regular manual link, so it is the portal policy of manual links that
	 * answers for its availability. The commands of Disk exempt the service link from that policy, which
	 * is why the answer of the two gateways differs.
	 */
	public function canCreate(): Result
	{
		if (!Loader::includeModule('disk'))
		{
			return self::diskUnavailable();
		}

		if (!Configuration::isEnabledManualExternalLink())
		{
			// the refusal of the previous way is answered with the code the previous way answered with:
			// that answer mixes the portal policy of manual links with the tariff, and telling the sender
			// to change the tariff because an administrator forbade public links would be a lie
			return self::diskUnavailable();
		}

		$result = new Result();
		$result->setData(['available' => true]);

		return $result;
	}

	public function createsServiceLink(): bool
	{
		return false;
	}

	public function create(int $userId, int $objectId): Result
	{
		if (!Loader::includeModule('disk'))
		{
			return self::diskUnavailable();
		}

		$object = BaseObject::loadById($objectId);
		if (!$object instanceof BaseObject)
		{
			return self::uploadFailed();
		}

		// unlike the command, this way is not idempotent: an object that already carries a link gets
		// another one, and the caller deletes the previous one when it finalizes the replacement
		$externalLink = $object->addExternalLink([
			'CREATED_BY' => $userId,
			'TYPE' => ExternalLink::TYPE_MANUAL,
			'ACCESS_RIGHT' => ExternalLink::ACCESS_RIGHT_VIEW,
			'CAN_DOWNLOAD_WITH_READ_ACCESS' => 1,
			'CAN_EDIT_SETTINGS' => false,
		]);
		if (!$externalLink instanceof ExternalLink)
		{
			return self::uploadFailed();
		}

		$result = new Result();
		$result->setData([
			'externalLinkId' => (int)$externalLink->getId(),
			'url' => $externalLink->generateUrl()->getUri(),
		]);

		return $result;
	}

	private static function diskUnavailable(): Result
	{
		return self::error(
			'Large attachment disk API is unavailable.',
			LargeAttachmentStorageInterface::ERROR_DISK_UNAVAILABLE,
		);
	}

	private static function uploadFailed(): Result
	{
		return self::error(
			'Could not create a large attachment link.',
			RealLargeAttachmentStorage::ERROR_UPLOAD_FAILED,
		);
	}

	private static function error(string $message, string $code): Result
	{
		$result = new Result();

		return $result->addError(new Error($message, $code));
	}
}


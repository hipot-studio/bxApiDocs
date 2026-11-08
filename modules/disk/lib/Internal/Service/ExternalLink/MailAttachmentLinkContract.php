<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

/**
 * Fields of a service link of the mail attachments scenario, in one place for both writers of it:
 * {@see MailAttachmentExternalLinkService} and the generic path
 * {@see \Bitrix\Disk\BaseObject::addExternalLink()}, which re-creates the link of a marked object.
 *
 * A service link opens without asking the portal policy, so its settings come from the scenario and
 * never from the caller.
 */
final class MailAttachmentLinkContract
{
	/**
	 * Only the manual link, the one the letter carries, is allowed to bypass the portal policy: a link
	 * of another type has to be refused here instead of quietly becoming a manual one.
	 *
	 * @throws InvalidArgumentException if the caller asks for a link of another type
	 */
	public static function applyTo(array $data, ?DateTime $deathTime = null): array
	{
		if ((int)($data['TYPE'] ?? 0) !== ExternalLink::TYPE_MANUAL)
		{
			throw new InvalidArgumentException(
				'A service link of the mail attachments scenario has to be a manual one',
			);
		}

		$data['SCENARIO'] = ExternalLinkScenario::MailAttachments->value;
		$data['ACCESS_RIGHT'] = ExternalLink::ACCESS_RIGHT_VIEW;
		$data['CAN_DOWNLOAD_WITH_READ_ACCESS'] = 1;
		$data['CAN_EDIT_SETTINGS'] = false;

		unset($data['PASSWORD'], $data['SALT'], $data['DEATH_TIME']);

		if ($deathTime !== null)
		{
			$data['DEATH_TIME'] = $deathTime;
		}

		return $data;
	}
}

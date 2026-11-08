<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Document\Models\DocumentSession;

/**
 * Delivers a report about a save to the channel of the document.
 *
 * The object may be gone by the time the session ends - moved to the recycle bin while the editing went
 * on - and the channel of the document is the channel of that object, so there is nowhere left to report
 * to. Neither the webhook nor the agent may fall over that: both answer for the session all the same.
 */
// Not final: the report of an interrupted session is read in tests through a subclass of the delivery.
class DocumentChannelReporter
{
	/**
	 * @return bool whether the report reached the channel.
	 */
	public function send(DocumentSession $documentSession, string $command, array $report): bool
	{
		$object = $documentSession->getObject();
		if (!$object)
		{
			return false;
		}

		$object->makeObjectEvent($command, $report)->sendToObjectChannel();

		return true;
	}
}

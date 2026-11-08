<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save\Messenger;

use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam;

/**
 * A request of a save of the document the editor server kept, deferred until the editor server has
 * brought that document back: when a user connects, it still has nothing to save.
 */
class KeptDocumentSaveMessage extends AbstractMessage
{
	public const QUEUE = 'onlyoffice_kept_document_save';
	public const DELAY = 15;

	public function __construct(
		public readonly int $sessionId,
		public readonly int $attempt,
	)
	{
	}

	public function schedule(): void
	{
		$this->send(self::QUEUE, [new DelayParam(self::DELAY)]);
	}
}

<?php

namespace Bitrix\Disk\Document\Flipchart\Messenger;

use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;

class DownloadBoardMessage extends AbstractMessage
{

	public const DELAY = 30;
	public const MAX_ATTEMPTS = 10;

	public function __construct(
		public readonly string $sessionId,
		public readonly mixed $userId,
		public readonly ?bool $isNewBoard,
		public int $attempt = 0,
	)
	{
		$this->attempt++;
	}

	/**
	 * @throws ConfigurationException
	 * @throws SendFailedException
	 */
	public function schedule(): void
	{
		if ($this->attempt > self::MAX_ATTEMPTS)
		{
			return;
		}

		$delay = new DelayParam(self::DELAY);
		$this->send('download_board', [$delay]);
	}

}

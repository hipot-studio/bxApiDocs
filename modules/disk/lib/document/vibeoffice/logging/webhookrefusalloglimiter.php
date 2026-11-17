<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;

final class WebhookRefusalLogLimiter
{
	private const WINDOW_SECONDS = 60;
	private const STATE_LENGTH = 20;

	public function __construct(private readonly ?string $stateFile = null)
	{
	}

	public function allow(): bool
	{
		$handle = null;
		try
		{
			$file = $this->stateFile ?? Application::getDocumentRoot() . Application::getPersonalRoot()
				. '/tmp/disk/vibeoffice-webhook-refusal.state';
			$directory = dirname($file);
			if (!is_dir($directory))
			{
				@Directory::createDirectory($directory);
			}
			// Keep the inode stable: replacing or deleting it would split concurrent workers' locks.
			$handle = @fopen($file, 'c+b');
			if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB))
			{
				return false;
			}
			$state = @fread($handle, self::STATE_LENGTH + 1);
			if ($state === false || ($state !== '' && preg_match('/^[0-9]{20}$/D', $state) !== 1))
			{
				return false;
			}
			$now = time();
			if ($state !== '' && (int)$state > $now)
			{
				return false;
			}
			$nextAllowed = str_pad((string)($now + self::WINDOW_SECONDS), self::STATE_LENGTH, '0', STR_PAD_LEFT);

			// Persist the next admission before the sink is reached; suppressed requests never write.
			return @fseek($handle, 0) === 0
				&& @fwrite($handle, $nextAllowed) === self::STATE_LENGTH
				&& @ftruncate($handle, self::STATE_LENGTH)
				&& @fflush($handle)
			;
		}
		catch (\Throwable)
		{
			return false;
		}
		finally
		{
			if (is_resource($handle))
			{
				@fclose($handle);
			}
		}
	}
}

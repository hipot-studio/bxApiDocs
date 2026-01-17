<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\HistoryBuilder;

use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Message\Text\BbCode\User;
use Bitrix\Im\V2\MessageCollection;

class Message
{
	private const MERGE_TIME_DELTA = 120; // 2 minutes
	private const DATE_FORMAT = '[Y-m-d H:i P]';

	private \Bitrix\Im\V2\Message $message;
	/**
	 * @var \Bitrix\Im\V2\Message[]
	 */
	private array $continuationMessages = [];
	private ?MessageCollection $messagePool;
	private bool $withReply = true;

	public function __construct(\Bitrix\Im\V2\Message $message, ?MessageCollection $messagePool = null)
	{
		$this->message = $message;
		$this->messagePool = $messagePool;
	}

	public function withoutReply(): self
	{
		$this->withReply = false;

		return $this;
	}

	public function getMessageBlock(): string
	{
		return "{$this->getDate()} {$this->getAuthorMarker()}: {$this->getMessageBody()}";
	}

	public function getChatId(): int
	{
		return $this->message->getChatId() ?? 0;
	}

	public function getAuthorId(): int
	{
		return $this->message->getAuthorId();
	}

	public function shouldContinueWith(\Bitrix\Im\V2\Message $message): bool
	{
		$dateTargetMessage = $this->message->getDateCreate()?->getTimestamp();
		$dateNewMessage = $message->getDateCreate()?->getTimestamp();

		if ($dateTargetMessage === null || $dateNewMessage === null)
		{
			return false;
		}

		return
			$message->getAuthorId() === $this->message->getAuthorId()
			&& abs($dateTargetMessage - $dateNewMessage) < self::MERGE_TIME_DELTA
		;
	}

	public function addContinuation(\Bitrix\Im\V2\Message $message): void
	{
		$this->continuationMessages[] = $message;
	}

	private function getDate(): string
	{
		return ($this->message->getDateCreate()?->format(self::DATE_FORMAT) ?? '');
	}

	private function getAuthorMarker(): string
	{
		if ($this->message->isSystem())
		{
			return 'System';
		}

		return User::build($this->message->getAuthorId())->compile();
	}

	private function getMessageBody(): string
	{
		$messages = array_map(
			fn(\Bitrix\Im\V2\Message $message) => $this->getOneMessageBody($message),
			[$this->message, ...$this->continuationMessages]
		);

		return implode("\n", $messages);
	}

	private function getOneMessageBody(\Bitrix\Im\V2\Message $message): string
	{
		$reply = $this->getReplyMessageText($message);
		if ($reply)
		{
			$reply .= "\n";
		}
		$textMessage = $message->getMessage();
		$filesInfo = $this->getFilesInfo($message);
		$attachmentsInfo = $this->getAttachmentsInfo($message);

		return "{$reply}{$textMessage}\n{$filesInfo}\n{$attachmentsInfo}";
	}

	private function getReplyMessageText(\Bitrix\Im\V2\Message $message): string
	{
		if (!$this->withReply || $this->messagePool === null)
		{
			return '';
		}

		$replyId = $message->getReplyId();
		if (!$replyId)
		{
			return '';
		}

		$reply = $this->messagePool[$replyId] ?? null;
		if (!$reply)
		{
			return '';
		}

		return History::wrapIntoBlock(
			History::QUOTE,
			(new self($reply, $this->messagePool))->withoutReply()->getMessageBlock()
		);
	}

	private function getFilesInfo(\Bitrix\Im\V2\Message $message): string
	{
		if (!$message->hasFiles())
		{
			return '';
		}

		$files = $message->getFiles();
		$fileDescriptions = [];

		foreach ($files as $file)
		{
			$diskFile = $file->getDiskFile();
			if (!$diskFile)
			{
				continue;
			}

			$fileName = $diskFile->getName();
			$fileSize = $diskFile->getSize();
			$fileSizeFormatted = \CFile::FormatSize($fileSize);
			$fileType = $file->getContentType();

			$description = "- {$fileName} ({$fileSizeFormatted}, {$fileType})";

			if ($file->isTranscribable())
			{
				$transcription = $this->getFileTranscription($file);
				if ($transcription)
				{
					$description .= "\n  Transcription: {$transcription}";
				}
			}

			$fileDescriptions[] = $description;
		}

		if (empty($fileDescriptions))
		{
			return '';
		}

		return "[Files]:\n" . implode("\n", $fileDescriptions);
	}

	private function getAttachmentsInfo(\Bitrix\Im\V2\Message $message): string
	{
		$attachData = $message->getAttach()->toRestFormat();

		if (empty($attachData))
		{
			return '';
		}

		return "[Attachments]:\n" . json_encode($attachData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	}

	private function getFileTranscription(FileItem $file): ?string
	{
		return $file->getCompletedTranscription()?->transcriptText;
	}
}

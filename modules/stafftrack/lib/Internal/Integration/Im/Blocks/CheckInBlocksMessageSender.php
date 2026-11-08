<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

use Bitrix\Disk\File;
use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Builder;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\SpaceDividerSize;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity\TitleSize;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Public\Command\CheckInDto;

class CheckInBlocksMessageSender
{
	private const LOG_PREFIX = 'StaffTrack check-in blocks message: ';

	public function send(CheckInDto $checkInDto): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not available'));
		}

		if (empty($checkInDto->dialogIds))
		{
			return $result->addError(new Error('Dialog ids not found'));
		}

		$userId = (int)CurrentUser::get()->getId();
		$isFileBlockAvailable = self::isFileBlockAvailable();
		$attachments = $this->resolveAttachments($checkInDto->fileIds, $userId);
		$plan = CheckInAttachmentPlanner::plan($attachments, $isFileBlockAvailable);
		$messageIds = [];

		foreach ($checkInDto->dialogIds as $dialogId)
		{
			$messageId = $this->sendToDialog(
				$checkInDto,
				(string)$dialogId,
				$userId,
				$attachments,
				$plan,
				$isFileBlockAvailable,
			);
			if (!empty($messageId))
			{
				$messageIds[] = $messageId;
			}
		}

		$checkInDto->messageIds = $messageIds;

		return $result->setData([
			'messageIds' => $messageIds,
		]);
	}

	/**
	 * @param list<CheckInAttachment> $attachments
	 */
	private function sendToDialog(
		CheckInDto $checkInDto,
		string $dialogId,
		int $userId,
		array $attachments,
		CheckInAttachmentPlan $plan,
		bool $isFileBlockAvailable,
	): ?int
	{
		$context = [
			'checkInId' => $checkInDto->id,
			'userId' => $userId,
			'dialogId' => $dialogId,
		];

		$chat = $this->resolveChat($dialogId, $userId);
		if ($chat === null)
		{
			self::log('chat is not available', $context);

			return null;
		}

		$chatId = (int)$chat->getId();

		if (!$this->canSendToChat($chat))
		{
			self::log('sending to the chat is not allowed', $context + ['chatId' => $chatId]);

			return null;
		}

		$allowedFileIds = self::toAttachmentFileIds($attachments);
		$blockFileIds = array_merge($plan->galleryFileIds, $plan->fileBlockFileIds);
		if ($blockFileIds !== [])
		{
			$chat->getOrCreateDiskFolder();
		}

		$copies = $this->uploadAttachments($chat, $blockFileIds, $userId, self::toDiskFilesById($attachments));
		$notCopiedFileIds = array_values(array_diff($blockFileIds, array_keys($copies)));
		if ($notCopiedFileIds !== [])
		{
			self::log('attachments were not copied', $context + ['fileIds' => implode('|', $notCopiedFileIds)]);
		}

		$blocks = CheckInBlocksComposer::compose(
			$checkInDto->description,
			$checkInDto->address,
			(string)$checkInDto->snapshotUrl,
			self::toChatFileIds($plan->galleryFileIds, $copies),
			self::toChatFileIds($plan->fileBlockFileIds, $copies),
		);

		$builderResult = self::applyBlocks($blocks, new Builder($chat))->build();
		$blocksBuilder = $builderResult->getBlocksBuilder();
		if (!$builderResult->isSuccess() || $blocksBuilder === null)
		{
			self::log(
				'blocks were not built',
				$context + ['errorCodes' => self::toErrorCodes($builderResult), 'fallback' => 'plainMessage'],
			);
			$this->discardAttachmentCopies($chat, array_values($copies), $userId);

			return $this->sendPlainMessage($checkInDto, $dialogId, $userId, $allowedFileIds);
		}

		$message = (new Message())
			->setContextUser($userId)
			->setBlocksBuilder($blocksBuilder)
		;

		$sendResult = $chat->sendMessage($message);
		if (!$sendResult->isSuccess())
		{
			self::log(
				'message was not sent',
				$context + ['errorCodes' => self::toErrorCodes($sendResult), 'fallback' => 'plainMessage'],
			);
			$this->discardAttachmentCopies($chat, array_values($copies), $userId);

			return $this->sendPlainMessage($checkInDto, $dialogId, $userId, $allowedFileIds);
		}

		$fallbackFileIds = array_merge($plan->fallbackFileIds, $notCopiedFileIds);
		if ($fallbackFileIds !== [])
		{
			self::log(
				'attachments went the fallback way',
				$context + self::fallbackReasons($attachments, $plan, $notCopiedFileIds, $isFileBlockAvailable),
			);
			$this->sendAttachments($chatId, $fallbackFileIds, $userId, $context);
		}

		return $sendResult->getMessageId();
	}

	protected function resolveChat(string $dialogId, int $userId): ?Chat
	{
		$chatId = (int)Dialog::getChatId($dialogId, $userId);
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId)->withContextUser($userId);

		return empty($chat->getId()) ? null : $chat;
	}

	/**
	 * @param list<int> $fileIds
	 */
	protected function sendPlainMessage(CheckInDto $checkInDto, string $dialogId, int $userId, array $fileIds): ?int
	{
		$messageId = MessageService::sendPlainToDialog($checkInDto, $dialogId, $userId, $fileIds);

		return $messageId > 0 ? $messageId : null;
	}

	protected function canSendToChat(Chat $chat): bool
	{
		return $chat->canDo(Action::Send);
	}

	/**
	 * @param array $fileIds
	 * @return list<CheckInAttachment>
	 */
	protected function resolveAttachments(array $fileIds, int $userId): array
	{
		return CheckInAttachmentTypeResolver::resolve($fileIds, $userId);
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<int, File> $diskFilesById
	 * @return array<int, int>
	 */
	protected function uploadAttachments(Chat $chat, array $fileIds, int $userId, array $diskFilesById): array
	{
		return (new ChatFileUploader($chat))->upload($fileIds, $userId, $diskFilesById);
	}

	/**
	 * @param list<int> $copyIds
	 */
	protected function discardAttachmentCopies(Chat $chat, array $copyIds, int $userId): void
	{
		(new ChatFileUploader($chat))->discard($copyIds, $userId);
	}

	private static function isFileBlockAvailable(): bool
	{
		return method_exists(Builder::class, 'addFileBlock')
			&& method_exists(Features::class, 'isMessageFileAudioBlockAvailable')
			&& Features::isMessageFileAudioBlockAvailable();
	}

	/**
	 * @param list<CheckInAttachment> $attachments
	 * @return array<int, File>
	 */
	private static function toDiskFilesById(array $attachments): array
	{
		$indexed = [];

		foreach ($attachments as $attachment)
		{
			if ($attachment->diskFile !== null)
			{
				$indexed[$attachment->diskFileId] = $attachment->diskFile;
			}
		}

		return $indexed;
	}

	/**
	 * @param list<CheckInAttachment> $attachments
	 * @return list<int>
	 */
	private static function toAttachmentFileIds(array $attachments): array
	{
		return array_map(static fn (CheckInAttachment $attachment): int => $attachment->diskFileId, $attachments);
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<string, int|string> $context
	 */
	protected function sendAttachments(int $chatId, array $fileIds, int $userId, array $context): void
	{
		if ($fileIds === [])
		{
			return;
		}

		$diskFileIds = array_map(static fn ($fileId): string => 'disk' . (int)$fileId, $fileIds);
		$context['fileCount'] = count($fileIds);

		try
		{
			if (\CIMDisk::UploadFileFromDisk($chatId, $diskFileIds, '', ['USER_ID' => $userId]) !== false)
			{
				return;
			}
		}
		catch (\Throwable $exception)
		{
			$context['errorCodes'] = $exception::class;
		}

		self::log('attachments message was not sent', $context);
	}

	/**
	 * @param list<CheckInAttachment> $attachments
	 * @param list<int> $notCopiedFileIds
	 * @return array<string, int>
	 */
	private static function fallbackReasons(
		array $attachments,
		CheckInAttachmentPlan $plan,
		array $notCopiedFileIds,
		bool $isFileBlockAvailable,
	): array
	{
		$mediaFileIds = [];
		foreach ($attachments as $attachment)
		{
			if ($attachment->isMedia)
			{
				$mediaFileIds[] = $attachment->diskFileId;
			}
		}

		$mediaOverflow = count(array_intersect($plan->fallbackFileIds, $mediaFileIds));
		$otherOverflow = count($plan->fallbackFileIds) - $mediaOverflow;

		$reasons = [];

		if ($mediaOverflow > 0)
		{
			$reasons['galleryLimit'] = $mediaOverflow;
		}

		if ($otherOverflow > 0)
		{
			$reasons[$isFileBlockAvailable ? 'fileBlockLimit' : 'fileBlockUnavailable'] = $otherOverflow;
		}

		if ($notCopiedFileIds !== [])
		{
			$reasons['copyFailed'] = count($notCopiedFileIds);
		}

		return $reasons;
	}

	private static function toErrorCodes(Result $result): string
	{
		$codes = array_map(static fn (Error $error): string => (string)$error->getCode(), $result->getErrors());

		return implode('|', $codes);
	}

	/**
	 * @param array<string, int|string> $context
	 */
	private static function log(string $event, array $context): void
	{
		$pairs = [];
		foreach ($context as $key => $value)
		{
			$pairs[] = $key . '=' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value);
		}

		\AddMessage2Log(self::LOG_PREFIX . $event . ': ' . implode(', ', $pairs));
	}

	/**
	 * @param list<CheckInBlock> $blocks
	 */
	private static function applyBlocks(array $blocks, Builder $builder): Builder
	{
		foreach ($blocks as $block)
		{
			match ($block->type)
			{
				CheckInBlockType::Title => $builder->addTitleBlock(
					$block->data['text'],
					self::toTitleSize($block->data['size']),
				),
				CheckInBlockType::Text => $builder->addTextBlock($block->data['text']),
				CheckInBlockType::SpaceDivider => $builder->addSpaceDividerBlock(
					self::toSpaceDividerSize($block->data['size']),
				),
				CheckInBlockType::Gallery => $builder->addGalleryBlock($block->data['fileIds']),
				CheckInBlockType::File => $builder->addFileBlock($block->data['fileIds']),
				CheckInBlockType::LineDivider => $builder->addLineDividerBlock(),
				CheckInBlockType::Map => $builder->addMapBlock($block->data['imageUrl'], $block->data['text']),
			};
		}

		return $builder;
	}

	private static function toTitleSize(string $size): TitleSize
	{
		return match ($size)
		{
			CheckInBlock::TITLE_SIZE_MEDIUM => TitleSize::Medium,
			default => TitleSize::Small,
		};
	}

	private static function toSpaceDividerSize(string $size): SpaceDividerSize
	{
		return match ($size)
		{
			CheckInBlock::SPACE_DIVIDER_SIZE_SMALL => SpaceDividerSize::Small,
			default => SpaceDividerSize::Medium,
		};
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<int, int> $copies
	 * @return list<int>
	 */
	private static function toChatFileIds(array $fileIds, array $copies): array
	{
		$chatFileIds = [];

		foreach ($fileIds as $fileId)
		{
			if (isset($copies[$fileId]))
			{
				$chatFileIds[] = $copies[$fileId];
			}
		}

		return $chatFileIds;
	}
}

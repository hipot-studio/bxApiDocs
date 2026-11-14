<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\Action;

use Bitrix\AiAssistant\Core\Dto\HintButton;
use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\AiAssistant\Trigger\Service\Exception\InvalidRequestException;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Integration\AiAssistant\FileChatAvailability;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class FileChatTrigger extends BaseAction
{
	private const MAX_FILE_NAME_LENGTH = 100;

	/**
	 * Full-width look-alikes: a bracket that can no longer open or close a tag. Every bracket goes,
	 * not a list of tags: the messenger reads any bracketed word as markup, "[BR]" as a line break.
	 */
	private const NEUTRALISED_BRACKETS = ['[' => "\u{FF3B}", ']' => "\u{FF3D}"];

	private const BUTTON_TITLES = [
		'summary' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_SUMMARY',
		'review' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_REVIEW',
		'history' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_HISTORY',
	];

	/**
	 * What the model needs to hear does not fit on a button: each sends an instruction instead of the title,
	 * and the instruction names the file, since "this file" sends the model to the folder in the page address.
	 */
	private const BUTTON_PROMPTS = [
		'summary' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_SUMMARY_PROMPT',
		'review' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_REVIEW_PROMPT',
		'history' => 'DISK_AIASSISTANT_FILE_CHAT_SUGGEST_HISTORY_PROMPT',
	];

	/** Files already cleared for a user in this operation: the title, the message and the buttons ask for the same one. */
	private array $resolvedFiles = [];

	public function __construct(
		private readonly FileChatAvailability $availability,
	)
	{
	}

	public static function getCode(): string
	{
		return 'disk.file-chat';
	}

	public function isEntityBound(): bool
	{
		return true;
	}

	public function getSuggestedTitle(?string $entityId = null): ?string
	{
		$file = $this->resolveFile($entityId);

		return Loc::getMessage(
			'DISK_AIASSISTANT_FILE_CHAT_TITLE',
			['#FILE_NAME#' => $this->prepareFileName($file)],
		);
	}

	public function getMessage(?string $entityId = null): string
	{
		$file = $this->resolveFile($entityId);

		return (string)Loc::getMessage(
			'DISK_AIASSISTANT_FILE_CHAT_MESSAGE',
			['#FILE_NAME#' => $this->prepareFileName($file)],
		);
	}

	/** @return HintButton[] */
	public function getInitialButtons(int $userId, ?string $entityId, int $chatId = 0): array
	{
		$buttons = $this->createButtons(self::BUTTON_TITLES);
		if ($buttons === [])
		{
			return [];
		}

		$fileName = $this->prepareFileName($this->resolveFile($entityId, $userId));

		return array_map(
			fn (HintButton $button, string $type): HintButton => $this->withPrompt($button, $type, $fileName),
			$buttons,
			array_keys(self::BUTTON_TITLES),
		);
	}

	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		return false;
	}

	protected function runAction(int $userId, int $progressId): bool
	{
		return true;
	}

	protected function needCompleteAfterRun(): bool
	{
		return false;
	}

	protected function getAvailability(): FileChatAvailability
	{
		return $this->availability;
	}

	protected function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function withPrompt(HintButton $button, string $type, string $fileName): HintButton
	{
		$prompt = isset(self::BUTTON_PROMPTS[$type])
			? Loc::getMessage(self::BUTTON_PROMPTS[$type], ['#FILE_NAME#' => $fileName])
			: null
		;
		if ($prompt === null || $prompt === '')
		{
			return $button;
		}

		return HintButton::sendText($button->title, $prompt, $button->style, $button->keepActive);
	}

	/**
	 * @param int|null $userId The user the caller vouches for; the session user when the caller has none.
	 */
	private function resolveFile(?string $entityId, ?int $userId = null): File
	{
		if (
			$entityId === null
			|| preg_match('/^[1-9][0-9]*$/D', $entityId) !== 1
			|| (string)(int)$entityId !== $entityId
		)
		{
			throw new InvalidRequestException('invalid_entity', 'A positive file ID is required');
		}

		$userId ??= $this->getUserId();

		return $this->resolvedFiles[$userId][$entityId]
			??= $this->getAvailability()->requireAvailableFile((int)$entityId, $userId);
	}

	/**
	 * The name is written by whoever shared the file and lands in the chat history the model reads,
	 * so it goes in as plain bounded text: no line breaks, no ASCII square brackets, no unbounded length.
	 */
	private function prepareFileName(File $file): string
	{
		$name = preg_replace('/[[:cntrl:]]+/', ' ', (string)$file->getName()) ?? '';
		$name = strtr($name, self::NEUTRALISED_BRACKETS);
		$name = trim(preg_replace('/ {2,}/', ' ', $name) ?? $name);

		if (mb_strlen($name) <= self::MAX_FILE_NAME_LENGTH)
		{
			return $name;
		}

		return rtrim(mb_substr($name, 0, self::MAX_FILE_NAME_LENGTH - 1)) . '…';
	}
}

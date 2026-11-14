<?php

namespace Bitrix\Mobile\BitrixGpt\Voice;

use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Im\V2\Public\Provider\Copilot\AnswerProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class Answer
{
	public function __construct(
		private readonly ?MarkdownToBBCodeTranslationService $markdownConverter,
		private readonly bool $nativeMarkdownEnabled,
		private readonly ?AnswerProvider $answerProvider,
	)
	{
	}

	/**
	 * Reads the final reply to a question owned by the authenticated user.
	 *
	 * @return Result Data contains pending, completed or failed status and optional message text.
	 */
	public function get(int $userId, int $messageId): Result
	{
		if ($this->answerProvider === null)
		{
			return $this->accessDenied();
		}

		$result = $this->answerProvider->get($userId, $messageId);
		if (!$result->isSuccess() || $result->getData()['status'] !== 'completed')
		{
			return $result;
		}

		return (new Result())->setData([
			'status' => 'completed',
			'text' => $this->convertToBBCode($this->getReplyText($result->getData()['message'])),
		]);
	}

	private function getReplyText(array $reply): string
	{
		$blocks = $reply['blocks'];
		if ($blocks === null)
		{
			return $reply['text'];
		}

		$text = [];
		$sources = [];
		foreach ($blocks['elements'] as $block)
		{
			if ($block['type'] !== 'text')
			{
				continue;
			}
			$text[] = $block['text'];
			foreach ($block['sources'] ?? [] as $source)
			{
				$sources[] = $source['url'];
			}
		}

		return implode("\n\n", [...$text, ...array_unique($sources)]);
	}

	private function accessDenied(): Result
	{
		return (new Result())->addError(new Error('Question is unavailable.', 'ACCESS_DENIED'));
	}

	private function convertToBBCode(string $text): string
	{
		if ($this->nativeMarkdownEnabled && $this->markdownConverter !== null)
		{
			return $this->markdownConverter->convert($text);
		}

		return $text;
	}
}

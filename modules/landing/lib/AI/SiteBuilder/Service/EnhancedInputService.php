<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Main\Result;

final class EnhancedInputService extends AbstractService
{
	public function execute(string $input, bool $forceZeroCost = false): Result
	{
		$input = trim($input);
		if ($input === '')
		{
			return $this->createError('Нужно заполнить ввод пользователя.', 'MISSING_INPUT', [
				'reason' => 'missing_input',
			]);
		}

		$meta = [
			'input_length' => mb_strlen($input),
		];

		return $this->requestPromptByCode(
			PromptCodeCatalog::CREATE_AI_SITE_INPUT_ENHANCE,
			[
				'{{input}}' => $input,
			],
			$meta,
			null,
			$forceZeroCost,
		);
	}
}

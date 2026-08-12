<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Landing\AI\SiteBuilder\Dto\TailwindBlockSpecDto;
use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class TailwindBlockHtmlService extends AbstractService
{
	public function execute(array $input, bool $forceZeroCost = false): Result
	{
		$blockSpecJson = trim((string)($input['block_spec_json'] ?? ''));
		$inputSource = 'json';
		$blockSpec = null;

		if ($blockSpecJson !== '')
		{
			try
			{
				$decoded = Json::decode($blockSpecJson);
			}
			catch (\Throwable $exception)
			{
				return $this->createError('Некорректный JSON в block_spec_json.', 'INVALID_BLOCK_SPEC_JSON', [
					'reason' => 'invalid_block_spec_json',
					'length' => mb_strlen($blockSpecJson),
				]);
			}

			if (!is_array($decoded))
			{
				return $this->createError('block_spec_json должен быть JSON-объектом.', 'BLOCK_SPEC_NOT_OBJECT', [
					'reason' => 'block_spec_not_object',
				]);
			}

			$blockSpec = TailwindBlockSpecDto::fromArray($decoded);
			if ($blockSpec === null)
			{
				return $this->createError('В block_spec_json должны быть заполнены все 8 полей tailwind-блока, включая navigation_title и images_count.', 'BLOCK_SPEC_MISSING_FIELDS', [
					'reason' => 'block_spec_missing_fields',
					'keys' => array_keys($decoded),
				]);
			}
		}
		else
		{
			$inputSource = 'fields';
			$blockSpec = TailwindBlockSpecDto::fromArray($input);

			if ($blockSpec === null)
			{
				return $this->createError('Нужно передать block_spec_json или заполнить все 8 параметров tailwind-блока.', 'MISSING_FIELDS', [
					'reason' => 'missing_fields',
				]);
			}
		}

		$normalizedBlockSpecJson = $blockSpec->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		if ($normalizedBlockSpecJson === null)
		{
			return $this->createError('Не удалось подготовить JSON-спецификацию tailwind-блока.', 'BLOCK_SPEC_ENCODE_FAILED', [
				'reason' => 'block_spec_encode_failed',
			]);
		}
		$blockSpecData = $blockSpec->toArray();
		$blockPosition = $this->normalizeBlockPosition($input['block_position'] ?? null);
		$siteMap = AiSiteAnchorContract::buildSingleEntrySiteMap(
			$blockPosition,
			(string)$blockSpecData['navigation_title'],
		);

		$meta = [
			'input_source' => $inputSource,
			'block_spec_json_length' => mb_strlen($normalizedBlockSpecJson),
		];

		return $this->requestPromptByCode(
			PromptCodeCatalog::CREATE_AI_SITE_BLOCK_HTML,
			[
				'{{block_spec_json}}' => $normalizedBlockSpecJson,
				'{{block_position}}' => (string)$blockPosition,
				'{{block_anchor}}' => AiSiteAnchorContract::buildHref($blockPosition - 1),
				'{{site_map_anchors_json}}' => AiSiteAnchorContract::encodeSiteMap($siteMap),
				'{{block_context}}' => (string)$blockSpecData['context'],
				'{{block_navigation_title}}' => (string)$blockSpecData['navigation_title'],
				'{{block_goal}}' => (string)$blockSpecData['goal'],
				'{{block_outline}}' => (string)$blockSpecData['outline'],
				'{{block_content}}' => (string)$blockSpecData['content'],
				'{{block_style}}' => (string)$blockSpecData['style'],
				'{{block_palette}}' => (string)$blockSpecData['palette'],
			],
			$meta,
			null,
			$forceZeroCost,
		);
	}

	private function normalizeBlockPosition(mixed $value): int
	{
		if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value)))
		{
			return max(1, (int)$value);
		}

		return 1;
	}
}

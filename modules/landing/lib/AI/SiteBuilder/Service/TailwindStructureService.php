<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Landing\AI\SiteBuilder\Dto\TailwindBlockSpecDto;
use Bitrix\Landing\AI\SiteBuilder\Parser\TolerantJsonParser;
use Bitrix\Landing\AI\SiteBuilder\Parser\TolerantJsonParserInterface;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Main\Result;

final class TailwindStructureService extends AbstractStructureService
{
	private TolerantJsonParserInterface $jsonParser;

	public function __construct(?TolerantJsonParserInterface $jsonParser = null)
	{
		$this->jsonParser = $jsonParser ?? new TolerantJsonParser();
	}

	public function execute(array $input, bool $forceZeroCost = false): Result
	{
		$normalizedResult = $this->normalizeStructureInput($input);
		if (!$normalizedResult->isSuccess())
		{
			return $normalizedResult;
		}

		$normalizedData = $normalizedResult->getData();
		$normalizedInput = isset($normalizedData['input']) && is_array($normalizedData['input'])
			? $normalizedData['input']
			: [];
		$meta = $this->extractMeta($normalizedData);

		$requestResult = $this->requestPromptByCode(
			PromptCodeCatalog::CREATE_AI_SITE_STRUCTURE,
			$this->buildStructurePromptReplacements($normalizedInput),
			$meta,
			null,
			$forceZeroCost,
		);
		if (!$requestResult->isSuccess())
		{
			return $requestResult;
		}

		$requestData = $requestResult->getData();
		if (isset($requestData['hash']) && $requestData['hash'] !== '')
		{
			return $requestResult;
		}

		$rawPayload = (string)($requestData['result'] ?? '');
		$validated = $this->validatePayload($rawPayload);
		if ($validated === null)
		{
			return $this->createError(
					'AI вернул некорректную структуру. Нужен непустой массив блоков с полями context, navigation_title, goal, outline, content, style, palette и images_count.',
				'INVALID_STRUCTURE',
				[
					'reason' => 'invalid_structure',
					'raw_length' => mb_strlen($rawPayload),
				],
				$this->extractMeta($requestData)
			);
		}

		return $this->createSuccessWithResult($validated, $this->extractMeta($requestData));
	}

	private function validatePayload(string $rawPayload): ?array
	{
		$payload = $this->jsonParser->parseArray($rawPayload);
		if (!is_array($payload) || $payload === [])
		{
			return null;
		}

		$validated = [];
		foreach ($payload as $item)
		{
			if (!is_array($item))
			{
				return null;
			}
			if (!TailwindBlockSpecDto::hasExactKeys($item))
			{
				return null;
			}

			$dto = TailwindBlockSpecDto::fromArray($item);
			if ($dto === null)
			{
				return null;
			}

			$validated[] = $dto->toArray();
		}

		return $validated;
	}
}

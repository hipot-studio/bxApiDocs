<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\RequestSingle;
use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\AI\SiteBuilder\Dto\TailwindBlockSpecDto;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\AI\SiteBuilder\Structure\TailwindStructureDiagnostics;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Metrika;

class RequestTailwindStructure extends RequestSingle
{
	private const IMAGES_MIN = 10;
	private const IMAGES_MAX = 20;

	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
	}

	public static function getConnectorClass(): string
	{
		return AI\CreateAiSiteText::class;
	}

	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return new RequestQuotaDto(self::getConnectorClass(), 1);
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::dataGeneration;
	}

	protected function getPrompt(): Prompt
	{
		$enhanced = EnhancedInputDto::fromArray(CreateAiSiteState::getEnhancedInput($this->generation));
		if ($enhanced === null)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Enhanced input is missing for tailwind structure request.',
			);
		}

		$enhancedData = $enhanced->toArray();
		$themeContext = $this->resolveThemeContext($enhancedData);
		if ($themeContext === '')
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Theme context is empty for tailwind structure request.',
			);
		}

		$markers = CreateAiSiteState::buildPromptMarkers([
			'{{theme}}' => $themeContext,
			'{{category}}' => (string)$enhancedData['category'],
			'{{title}}' => (string)($enhancedData['title'] !== '' ? $enhancedData['title'] : $themeContext),
			'{{brief}}' => (string)$enhancedData['brief'],
			'{{profile}}' => (string)$enhancedData['profile'],
			'{{audience}}' => (string)$enhancedData['audience'],
			'{{design}}' => (string)$enhancedData['design'],
			'{{offer}}' => (string)$enhancedData['offer'],
			'{{palette}}' => (string)$enhancedData['palette'],
			'{{images_min}}' => (string)self::IMAGES_MIN,
			'{{images_max}}' => (string)self::IMAGES_MAX,
		]);
		$prompt = new Prompt(PromptCodeCatalog::CREATE_AI_SITE_STRUCTURE);
		$prompt->setMarkers($markers);
		$prompt->setSchema(new Schema\CreateAiSiteTailwindStructure());

		return $prompt;
	}

	protected function getRequestLogDataType(): string
	{
		return 'AI request: site structure';
	}

	protected function applyResponse(): bool
	{
		$validated = $this->validateResult($this->request->getResult());
		if ($validated === null)
		{
			return false;
		}

		if (Log::isEnabled())
		{
			$log = new Log($this->generation->getId());
			$log->addResponse(
				(int)$this->stepId,
				'AI response: site structure',
				$this->request->getResult(),
			);
			$log->addStructureDiagnostics(
				(int)$this->stepId,
				TailwindStructureDiagnostics::analyze($validated),
			);
		}

		CreateAiSiteState::setStructure($this->generation, $validated);
		CreateAiSiteState::setHtmlBlocks($this->generation, $this->buildHtmlBlocks($validated));

		return true;
	}

	public function verifyResponse(): void
	{
		if ($this->validateResult($this->request->getResult()) === null)
		{
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Tailwind structure response is invalid.',
			);
		}
	}

	private function validateResult(?array $result): ?array
	{
		$structure = $this->extractStructureItems($result);
		if ($structure === null)
		{
			return null;
		}

		$validated = [];
		foreach ($structure as $item)
		{
			if (!is_array($item) || !TailwindBlockSpecDto::hasExactKeys($item))
			{
				return null;
			}

			$dto = TailwindBlockSpecDto::fromArray($item);
			if ($dto === null)
			{
				return null;
			}

			$blockSpec = $dto->toArray();
			if ((int)$blockSpec['images_count'] < 0)
			{
				return null;
			}

			$validated[] = $blockSpec;
		}

		return $this->normalizeImagesCountBudget($validated);
	}

	private function extractStructureItems(?array $result): ?array
	{
		if (!is_array($result) || $result === [])
		{
			return null;
		}

		if (array_is_list($result))
		{
			return $result;
		}

		$blocks = $result['blocks'] ?? null;
		if (!is_array($blocks) || $blocks === [] || !array_is_list($blocks))
		{
			return null;
		}

		return $blocks;
	}

	private function normalizeImagesCountBudget(array $structure): array
	{
		$total = $this->countImages($structure);
		if ($total > self::IMAGES_MAX)
		{
			return $this->reduceImagesCount($structure, $total - self::IMAGES_MAX);
		}

		if ($total < self::IMAGES_MIN)
		{
			return $this->increaseImagesCount($structure, self::IMAGES_MIN - $total);
		}

		return $structure;
	}

	private function reduceImagesCount(array $structure, int $excess): array
	{
		$cursor = 0;
		$count = count($structure);
		while ($excess > 0 && $count > 0)
		{
			$counts = array_map(
				static fn(array $item): int => max(0, (int)($item['images_count'] ?? 0)),
				$structure,
			);
			$max = max($counts);
			if ($max <= 0)
			{
				break;
			}

			$maxIndexes = $this->sortIndexesFromCursor(array_keys($counts, $max, true), $cursor, $count);
			$secondMax = 0;
			foreach ($counts as $index => $value)
			{
				if ($value !== $max)
				{
					$secondMax = max($secondMax, $value);
				}
			}

			$levelCapacity = ($max - $secondMax) * count($maxIndexes);
			if ($levelCapacity > 0 && $excess >= $levelCapacity)
			{
				foreach ($maxIndexes as $index)
				{
					$structure[$index]['images_count'] = $secondMax;
				}
				$lastMaxIndex = $maxIndexes[array_key_last($maxIndexes)];
				$cursor = ($lastMaxIndex + 1) % $count;
				$excess -= $levelCapacity;

				continue;
			}

			$fullRounds = intdiv($excess, count($maxIndexes));
			$remainder = $excess % count($maxIndexes);
			foreach ($maxIndexes as $index)
			{
				$structure[$index]['images_count'] = $max - $fullRounds;
			}
			foreach (array_slice($maxIndexes, 0, $remainder) as $index)
			{
				$structure[$index]['images_count'] = (int)$structure[$index]['images_count'] - 1;
			}
			$excess = 0;
		}

		return $structure;
	}

	private function sortIndexesFromCursor(array $indexes, int $cursor, int $count): array
	{
		usort(
			$indexes,
			static fn(int $left, int $right): int => (($left - $cursor + $count) % $count) <=> (($right - $cursor + $count) % $count),
		);

		return $indexes;
	}

	private function increaseImagesCount(array $structure, int $shortage): array
	{
		$cursor = 0;
		$count = count($structure);
		while ($shortage > 0 && $count > 0)
		{
			$structure[$cursor]['images_count'] = (int)$structure[$cursor]['images_count'] + 1;
			$cursor = ($cursor + 1) % $count;
			$shortage--;
		}

		return $structure;
	}

	private function countImages(array $structure): int
	{
		$total = 0;
		foreach ($structure as $item)
		{
			$total += max(0, (int)($item['images_count'] ?? 0));
		}

		return $total;
	}

	private function resolveThemeContext(array $enhancedData): string
	{
		foreach (['brief', 'title'] as $field)
		{
			$value = trim((string)($enhancedData[$field] ?? ''));
			if ($value !== '')
			{
				return $value;
			}
		}

		return CreateAiSiteState::resolveRawInput($this->generation);
	}

	private function buildHtmlBlocks(array $validated): array
	{
		$existingByPosition = [];
		foreach (CreateAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			$position = (int)($item['position'] ?? -1);
			if ($position >= 0)
			{
				$existingByPosition[$position] = $item;
			}
		}

		$htmlBlocks = [];
		foreach ($validated as $position => $blockSpec)
		{
			$current = $existingByPosition[$position] ?? [];
			$htmlBlocks[] = [
				'position' => $position,
				'spec' => $blockSpec,
				'html' => (string)($current['html'] ?? ''),
				'blockId' => (int)($current['blockId'] ?? 0),
				'anchor' => (string)($current['anchor'] ?? ''),
				'improved' => !empty($current['improved']),
				'aiMeta' => AiBlockHtmlPayloadProcessor::normalizeAiMeta($current['aiMeta'] ?? null),
			];
		}

		return $htmlBlocks;
	}
}

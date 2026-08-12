<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Main\Result;

abstract class AbstractStructureService extends AbstractService
{
	private const IMAGES_MIN = 10;
	private const IMAGES_MAX = 20;

	protected function normalizeStructureInput(array $input): Result
	{
		$theme = trim((string)($input['theme'] ?? ''));
		$category = trim((string)($input['category'] ?? ''));
		$title = trim((string)($input['title'] ?? ''));
		$brief = trim((string)($input['brief'] ?? ''));
		$profile = trim((string)($input['profile'] ?? ''));
		$audience = trim((string)($input['audience'] ?? ''));
		$design = trim((string)($input['design'] ?? ''));
		$offer = trim((string)($input['offer'] ?? ''));
		$palette = trim((string)($input['palette'] ?? ''));

		$themeContext = $brief !== '' ? $brief : ($title !== '' ? $title : $theme);
		if ($themeContext === '')
		{
			return $this->createError('Нужно заполнить тематику сайта.', 'MISSING_THEME', [
				'reason' => 'missing_theme',
			]);
		}

		$hasEnhanced = (
			$brief !== ''
			|| $title !== ''
			|| $category !== ''
			|| $profile !== ''
			|| $audience !== ''
			|| $design !== ''
			|| $offer !== ''
			|| $palette !== ''
		);

		$normalizedInput = [
			'theme' => $theme,
			'category' => $category,
			'title' => $title,
			'brief' => $brief,
			'profile' => $profile,
			'audience' => $audience,
			'design' => $design,
			'offer' => $offer,
			'palette' => $palette,
			'theme_context' => $themeContext,
		];

		return $this->createSuccess([
			'input' => $normalizedInput,
			'meta' => [
				'theme_context' => $themeContext,
				'has_enhanced' => $hasEnhanced,
			],
		]);
	}

	protected function buildStructurePromptReplacements(array $input): array
	{
		return [
			'{{theme}}' => (string)$input['theme_context'],
			'{{category}}' => (string)$input['category'],
			'{{title}}' => (string)($input['title'] !== '' ? $input['title'] : $input['theme_context']),
			'{{brief}}' => (string)$input['brief'],
			'{{profile}}' => (string)$input['profile'],
			'{{audience}}' => (string)$input['audience'],
			'{{design}}' => (string)$input['design'],
			'{{offer}}' => (string)$input['offer'],
			'{{palette}}' => (string)$input['palette'],
			'{{images_min}}' => (string)self::IMAGES_MIN,
			'{{images_max}}' => (string)self::IMAGES_MAX,
		];
	}
}

<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Structure;

final class TailwindStructureDiagnostics
{
	private const CHECK_STRUCTURE_NOT_EMPTY = 'structure_not_empty';
	private const CHECK_COUNT_RANGE = 'count_between_6_and_10';
	private const CHECK_NAVIGATION_TITLES_UNIQUE = 'navigation_titles_unique';
	private const CHECK_FIRST_SCREEN_SECOND = 'first_screen_second';
	private const CHECK_SINGLE_CRM_BLOCK = 'single_crm_block';
	private const CHECK_CRM_IN_SECOND_HALF = 'crm_in_second_half';
	private const CHECK_FOOTER_LAST = 'footer_last';
	private const CHECK_COVER_FULLSCREEN_PRESENT = 'cover_fullscreen_present';
	private const CHECK_OUTLINE_IMAGES_PRESENT = 'outline_images_count_present';
	private const CHECK_IMAGES_COUNT_OUTLINE_MATCH = 'images_count_outline_match';

	public static function analyze(array $structure): array
	{
		$report = [
			'passed' => [],
			'failed' => [],
			'warnings' => [],
			'summary' => self::buildSummary($structure),
		];

		self::checkStructureNotEmpty($structure, $report);
		self::checkCountRange($structure, $report);
		self::checkNavigationTitlesUnique($structure, $report);
		self::checkFirstScreenSecond($structure, $report);
		self::checkCrmBlocks($structure, $report);
		self::checkFooterLast($structure, $report);
		self::checkCoverFullscreenPresent($structure, $report);
		self::checkOutlineImages($structure, $report);

		return $report;
	}

	private static function buildSummary(array $structure): array
	{
		$navigationTitles = [];
		$duplicates = [];
		$seenTitles = [];
		$crmPositions = [];
		$footerPosition = null;
		$imagesTotal = 0;

		foreach ($structure as $index => $block)
		{
			$position = (int)$index + 1;
			$title = self::getNavigationTitle($block);
			$navigationTitles[] = $title;
			$normalizedTitle = self::normalizeText($title);
			if ($normalizedTitle !== '')
			{
				if (isset($seenTitles[$normalizedTitle]))
				{
					$duplicates[$normalizedTitle] = $title;
				}
				$seenTitles[$normalizedTitle] = true;
			}

			if (self::isCrmBlock($block))
			{
				$crmPositions[] = $position;
			}

			if (self::isFooterBlock($block))
			{
				$footerPosition = $position;
			}

			$imagesTotal += max(0, (int)($block['images_count'] ?? 0));
		}

		return [
			'blocks' => count($structure),
			'imagesTotal' => $imagesTotal,
			'crmPositions' => $crmPositions,
			'footerPosition' => $footerPosition,
			'navigationTitles' => $navigationTitles,
			'duplicateNavigationTitles' => array_values($duplicates),
		];
	}

	private static function checkStructureNotEmpty(array $structure, array &$report): void
	{
		if ($structure !== [])
		{
			self::pass($report, self::CHECK_STRUCTURE_NOT_EMPTY);

			return;
		}

		self::fail($report, self::CHECK_STRUCTURE_NOT_EMPTY, 'Structure is empty.');
	}

	private static function checkCountRange(array $structure, array &$report): void
	{
		$count = count($structure);
		if ($count >= 6 && $count <= 10)
		{
			self::pass($report, self::CHECK_COUNT_RANGE);

			return;
		}

		self::fail($report, self::CHECK_COUNT_RANGE, 'Structure block count is outside expected 6-10 range.', [
			'blocks' => $count,
		]);
	}

	private static function checkNavigationTitlesUnique(array $structure, array &$report): void
	{
		$seen = [];
		$duplicates = [];
		foreach ($structure as $index => $block)
		{
			$title = self::getNavigationTitle($block);
			$normalizedTitle = self::normalizeText($title);
			if ($normalizedTitle === '')
			{
				continue;
			}

			if (isset($seen[$normalizedTitle]))
			{
				$duplicates[] = [
					'blockPosition' => (int)$index + 1,
					'navigationTitle' => $title,
					'firstPosition' => $seen[$normalizedTitle],
				];
			}
			else
			{
				$seen[$normalizedTitle] = (int)$index + 1;
			}
		}

		if ($duplicates === [])
		{
			self::pass($report, self::CHECK_NAVIGATION_TITLES_UNIQUE);

			return;
		}

		self::warn($report, self::CHECK_NAVIGATION_TITLES_UNIQUE, 'Duplicate navigation titles were found.', [
			'duplicates' => $duplicates,
		]);
	}

	private static function checkFirstScreenSecond(array $structure, array &$report): void
	{
		$secondBlock = $structure[1] ?? null;
		if (is_array($secondBlock) && self::containsAny($secondBlock, ['тип: first-screen', 'first-screen']))
		{
			self::pass($report, self::CHECK_FIRST_SCREEN_SECOND);

			return;
		}

		self::fail($report, self::CHECK_FIRST_SCREEN_SECOND, 'Second block does not contain first-screen marker.', [
			'blockPosition' => 2,
			'navigationTitle' => is_array($secondBlock) ? self::getNavigationTitle($secondBlock) : null,
		]);
	}

	private static function checkCrmBlocks(array $structure, array &$report): void
	{
		$crmPositions = [];
		foreach ($structure as $index => $block)
		{
			if (self::isCrmBlock($block))
			{
				$crmPositions[] = (int)$index + 1;
			}
		}

		if (count($crmPositions) === 1)
		{
			self::pass($report, self::CHECK_SINGLE_CRM_BLOCK);
		}
		else
		{
			self::fail($report, self::CHECK_SINGLE_CRM_BLOCK, 'Structure should contain exactly one CRM block.', [
				'crmPositions' => $crmPositions,
			]);
		}

		$count = count($structure);
		$firstAllowedPosition = (int)floor($count / 2) + 1;
		if (count($crmPositions) === 1 && $crmPositions[0] >= $firstAllowedPosition)
		{
			self::pass($report, self::CHECK_CRM_IN_SECOND_HALF);

			return;
		}

		self::fail($report, self::CHECK_CRM_IN_SECOND_HALF, 'CRM block is not in the second half of the structure.', [
			'crmPositions' => $crmPositions,
			'firstAllowedPosition' => $firstAllowedPosition,
		]);
	}

	private static function checkFooterLast(array $structure, array &$report): void
	{
		$count = count($structure);
		$lastBlock = $count > 0 ? ($structure[$count - 1] ?? null) : null;
		if (is_array($lastBlock) && self::isFooterBlock($lastBlock))
		{
			self::pass($report, self::CHECK_FOOTER_LAST);

			return;
		}

		self::fail($report, self::CHECK_FOOTER_LAST, 'Last block does not look like Footer.', [
			'blockPosition' => $count,
			'navigationTitle' => is_array($lastBlock) ? self::getNavigationTitle($lastBlock) : null,
		]);
	}

	private static function checkCoverFullscreenPresent(array $structure, array &$report): void
	{
		foreach ($structure as $index => $block)
		{
			if (self::containsAny($block, ['cover-fullscreen', 'тип: cover-fullscreen', 'вариант: cover-fullscreen']))
			{
				self::pass($report, self::CHECK_COVER_FULLSCREEN_PRESENT);

				return;
			}
		}

		self::fail($report, self::CHECK_COVER_FULLSCREEN_PRESENT, 'No cover-fullscreen block marker was found.');
	}

	private static function checkOutlineImages(array $structure, array &$report): void
	{
		$missing = [];
		$mismatches = [];
		foreach ($structure as $index => $block)
		{
			$expected = max(0, (int)($block['images_count'] ?? 0));
			$outlineCount = self::extractOutlineImagesCount((string)($block['outline'] ?? ''));
			if ($outlineCount === null)
			{
				$missing[] = [
					'blockPosition' => (int)$index + 1,
					'navigationTitle' => self::getNavigationTitle($block),
					'imagesCount' => $expected,
				];

				continue;
			}

			if ($outlineCount !== $expected)
			{
				$mismatches[] = [
					'blockPosition' => (int)$index + 1,
					'navigationTitle' => self::getNavigationTitle($block),
					'imagesCount' => $expected,
					'outlineImagesCount' => $outlineCount,
				];
			}
		}

		if ($missing === [])
		{
			self::pass($report, self::CHECK_OUTLINE_IMAGES_PRESENT);
		}
		else
		{
			self::warn($report, self::CHECK_OUTLINE_IMAGES_PRESENT, 'Some blocks do not contain explicit outline image count marker.', [
				'blocks' => $missing,
			]);
		}

		if ($mismatches === [])
		{
			self::pass($report, self::CHECK_IMAGES_COUNT_OUTLINE_MATCH);

			return;
		}

		self::warn($report, self::CHECK_IMAGES_COUNT_OUTLINE_MATCH, 'Some blocks have images_count and outline image count mismatch.', [
			'blocks' => $mismatches,
		]);
	}

	private static function isCrmBlock(array $block): bool
	{
		$outline = mb_strtolower((string)($block['outline'] ?? ''));

		return str_contains($outline, 'crm form')
			|| str_contains($outline, 'crm form container')
			|| str_contains($outline, 'crm-форма')
			|| str_contains($outline, 'контейнер под crm-форму')
		;
	}

	private static function isFooterBlock(array $block): bool
	{
		return self::containsAny($block, ['footer', 'футер', 'копирайт', 'юридическая информация']);
	}

	private static function containsAny(array $block, array $markers): bool
	{
		$haystack = self::normalizeText(implode(' ', [
			(string)($block['navigation_title'] ?? ''),
			(string)($block['goal'] ?? ''),
			(string)($block['outline'] ?? ''),
			(string)($block['content'] ?? ''),
		]));

		foreach ($markers as $marker)
		{
			if ($marker !== '' && str_contains($haystack, self::normalizeText($marker)))
			{
				return true;
			}
		}

		return false;
	}

	private static function extractOutlineImagesCount(string $outline): ?int
	{
		if (preg_match('/(?:Images|Изображения)\s*:\s*(\d+)/iu', $outline, $matches) !== 1)
		{
			return null;
		}

		return (int)$matches[1];
	}

	private static function getNavigationTitle(array $block): string
	{
		return trim((string)($block['navigation_title'] ?? ''));
	}

	private static function normalizeText(string $text): string
	{
		return mb_strtolower(trim($text));
	}

	private static function pass(array &$report, string $check): void
	{
		$report['passed'][] = $check;
	}

	private static function fail(array &$report, string $check, string $message, array $details = []): void
	{
		$report['failed'][] = array_filter([
			'check' => $check,
			'message' => $message,
			'details' => $details,
		], static fn($value): bool => $value !== []);
	}

	private static function warn(array &$report, string $check, string $message, array $details = []): void
	{
		$report['warnings'][] = array_filter([
			'check' => $check,
			'message' => $message,
			'details' => $details,
		], static fn($value): bool => $value !== []);
	}
}

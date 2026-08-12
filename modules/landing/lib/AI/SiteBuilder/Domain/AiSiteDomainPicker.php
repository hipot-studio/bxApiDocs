<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Domain;

use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\Domain;
use CUtil;

final class AiSiteDomainPicker
{
	private const MAX_LENGTH = 63;
	private const SEPARATOR = '-';
	private const RANDOM_TAIL_LENGTH = 4;
	private const RANDOM_TAIL_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';
	// Safety ceiling only: the model answer is not a contract and every candidate costs a Domain::check() call.
	private const MAX_AI_CANDIDATES = 5;

	/**
	 * @return string[] Ordered list of full FQDN candidates: AI suggested ones first, then the
	 * transliterated title, the composite root and the random tail one (may be fewer after deduplication).
	 */
	public static function buildCandidates(EnhancedInputDto $enhanced, string $type = 'PAGE'): array
	{
		$suffix = Domain::getBitrix24Postfix($type);
		$budget = self::MAX_LENGTH - mb_strlen($suffix);

		$candidates = [];
		$aiRoots = [];
		// AI suggested slugs are latin by contract, so they only need normalization, not transliteration.
		foreach (array_slice($enhanced->getAiDomains(), 0, self::MAX_AI_CANDIDATES) as $aiDomain)
		{
			// The prompt asks for the left label only, but the model may answer with a full FQDN or with spaces:
			// normalizeSlug() would glue the parts together instead of cutting, so cut and separate them here.
			$aiDomain = strstr($aiDomain, '.', true) ?: $aiDomain;
			$aiDomain = preg_replace('/\s+/u', self::SEPARATOR, $aiDomain) ?? '';

			$root = self::fit(self::normalizeSlug($aiDomain), $budget);
			if ($root !== '')
			{
				$aiRoots[] = $root;
				$candidates[] = $root . $suffix;
			}
		}

		$rootTitle = self::normalizeSlug(self::translit($enhanced->getTitle()));
		$rootCategory = self::normalizeSlug(self::translit($enhanced->getCategory()));
		$rootCombined = self::normalizeSlug($rootTitle . self::SEPARATOR . $rootCategory);
		// With an empty transliteration the funnel would end on an AI name, which is taken without a check;
		// basing the random tail on the first AI slug keeps a practically free candidate last.
		$tailRoot = $rootCombined !== '' ? $rootCombined : ($rootTitle !== '' ? $rootTitle : ($aiRoots[0] ?? ''));

		if ($rootTitle !== '')
		{
			$candidates[] = self::fit($rootTitle, $budget) . $suffix;
		}
		if ($rootCombined !== '')
		{
			$candidates[] = self::fit($rootCombined, $budget) . $suffix;
		}
		if ($tailRoot !== '')
		{
			$reserved = self::RANDOM_TAIL_LENGTH + mb_strlen(self::SEPARATOR);
			$tailBase = self::fit($tailRoot, $budget - $reserved);
			if ($tailBase !== '')
			{
				$candidates[] = $tailBase . self::SEPARATOR . self::randomTail() . $suffix;
			}
		}

		return array_values(array_unique($candidates));
	}

	private static function translit(string $text): string
	{
		$params = [
			'max_len' => self::MAX_LENGTH,
			'change_case' => 'L',
			'replace_space' => self::SEPARATOR,
			'replace_other' => '',
			'delete_repeat_replace' => true,
		];

		return CUtil::translit($text, self::resolveTranslitLanguage($text), $params);
	}

	/**
	 * Site title language may differ from the interface one, and only ru/kz tables carry Cyrillic:
	 * with any other LANGUAGE_ID a Cyrillic seed would be dropped instead of transliterated.
	 */
	private static function resolveTranslitLanguage(string $text): string
	{
		return preg_match('/\p{Cyrillic}/u', $text) === 1 ? 'ru' : LANGUAGE_ID;
	}

	private static function normalizeSlug(string $value): string
	{
		$value = mb_strtolower($value);
		$value = preg_replace('/[^a-z0-9-]+/', '', $value) ?? '';
		$value = preg_replace('/-+/', self::SEPARATOR, $value) ?? '';

		return trim($value, self::SEPARATOR);
	}

	private static function fit(string $slug, int $limit): string
	{
		if ($limit <= 0)
		{
			return '';
		}
		if (mb_strlen($slug) <= $limit)
		{
			return $slug;
		}

		return rtrim(mb_substr($slug, 0, $limit), self::SEPARATOR);
	}

	private static function randomTail(): string
	{
		return randString(self::RANDOM_TAIL_LENGTH, self::RANDOM_TAIL_ALPHABET);
	}
}

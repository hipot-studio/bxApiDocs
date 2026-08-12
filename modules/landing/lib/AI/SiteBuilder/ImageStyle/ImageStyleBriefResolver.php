<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\ImageStyle;

final class ImageStyleBriefResolver
{
	private const MAX_LENGTH = 130;

	private const FALLBACK_RU = [
		'saas' => 'холодная high-key фотосъемка, резкий фокус, чистый микроконтраст',
		'finance' => 'сдержанная premium-фотография, холодный баланс, направленный свет, четкая перспектива',
		'healthcare' => 'чистая clinical high-key съемка, рассеянный свет, мягкий контраст',
		'wellness' => 'воздушная calm-фотография, пастельная палитра, малая глубина резкости',
		'food' => 'теплая tactile editorial-съемка, боковой свет, малая глубина резкости',
		'beauty' => 'глянцевая editorial-фотография, направленный свет, чистые тени',
		'travel' => 'энергичная outdoor-фотография, широкий угол, контровой свет, глубокий контраст',
		'real_estate' => 'архитектурная documentary-фотография, нейтральный баланс, четкая перспектива',
		'industrial' => 'документальная commercial-съемка, холодная палитра, жесткий микроконтраст',
		'education' => 'светлая documentary-фотография, дневной high-key, мягкая цветопередача',
		'culture' => 'cinematic low-key фотография, контровой свет, насыщенный контраст, зерно',
		'ecommerce' => 'чистая commercial-фотография, ровный свет, резкая детализация',
		'local_service' => 'документальная local-фотография, нейтральная палитра, четкая перспектива',
		'default' => 'чистая commercial-фотография, нейтральная палитра, резкая детализация',
	];

	private const FALLBACK_EN = [
		'saas' => 'cool high-key photography, crisp focus, clean microcontrast',
		'finance' => 'restrained premium photography, cool balance, directional light, clear perspective',
		'healthcare' => 'clean clinical high-key photography, diffused light, soft contrast',
		'wellness' => 'airy calm photography, pastel palette, shallow depth of field',
		'food' => 'warm tactile editorial photography, side light, shallow depth of field',
		'beauty' => 'glossy editorial photography, directional light, clean shadows',
		'travel' => 'energetic outdoor photography, wide angle, backlight, deep contrast',
		'real_estate' => 'architectural documentary photography, neutral balance, clear perspective',
		'industrial' => 'documentary commercial photography, cool palette, hard microcontrast',
		'education' => 'bright documentary photography, high-key daylight, soft color rendering',
		'culture' => 'cinematic low-key photography, backlight, saturated contrast, film grain',
		'ecommerce' => 'clean commercial photography, even light, sharp detail',
		'local_service' => 'documentary local photography, neutral palette, clear perspective',
		'default' => 'clean commercial photography, neutral palette, sharp detail',
	];

	private const ARCHETYPE_KEYWORDS = [
		'healthcare' => [
			'healthcare', 'medical', 'medicine', 'clinic', 'dental', 'dentist', 'pharmacy', 'doctor', 'hospital',
			'медицин', 'медицинск', 'медицина', 'клиник', 'стомат', 'аптек', 'врач', 'больниц', 'здоровье',
		],
		'wellness' => [
			'wellness', 'yoga', 'psychology', 'therapy', 'spa', 'massage', 'meditation',
			'велнес', 'йога', 'психолог', 'терап', 'спа', 'массаж', 'медитац',
		],
		'food' => [
			'restaurant', 'cafe', 'coffee', 'bakery', 'bar', 'hospitality', 'hotel', 'food', 'menu',
			'ресторан', 'кафе', 'кофе', 'кофейн', 'пекар', 'бар', 'отел', 'гостиниц', 'еда', 'меню',
		],
		'beauty' => [
			'beauty', 'fashion', 'cosmetic', 'salon', 'makeup', 'nails',
			'красот', 'мода', 'космет', 'салон', 'макияж', 'ногт', 'парикмах',
		],
		'travel' => [
			'travel', 'tour', 'outdoor', 'sport', 'fitness', 'expedition', 'adventure',
			'туризм', 'турист', 'путеше', 'спорт', 'фитнес', 'экспедиц', 'приключ', 'горн', 'маршрут',
		],
		'real_estate' => [
			'real estate', 'architecture', 'architectural', 'property', 'developer', 'apartment',
			'недвиж', 'архитект', 'застрой', 'жилой', 'квартир',
		],
		'industrial' => [
			'industrial', 'logistics', 'manufacturing', 'factory', 'warehouse', 'construction', 'production',
			'промышлен', 'логист', 'производ', 'завод', 'фабрик', 'склад', 'строител',
		],
		'education' => [
			'education', 'course', 'school', 'university', 'kids', 'children', 'nonprofit', 'charity',
			'образован', 'курс', 'школ', 'университет', 'дети', 'ребен', 'нко', 'благотвор',
		],
		'culture' => [
			'culture', 'event', 'media', 'festival', 'music', 'theatre', 'theater', 'concert', 'exhibition',
			'культур', 'событ', 'мероприят', 'медиа', 'фестив', 'музык', 'театр', 'концерт', 'выстав',
		],
		'finance' => [
			'finance', 'fintech', 'bank', 'legal', 'law', 'consulting', 'insurance', 'accounting',
			'финанс', 'финтех', 'банк', 'юрид', 'право', 'консалт', 'страхован', 'бухгалтер',
		],
		'saas' => [
			'saas', 'software', 'crm', 'b2b', 'automation', 'platform', 'app',
			'автоматизац', 'платформ', 'приложен', 'софт', 'crm', 'b2b', 'айти',
		],
		'ecommerce' => [
			'ecommerce', 'e commerce', 'online store', 'retail', 'shop', 'marketplace',
			'интернет магазин', 'магазин', 'маркетплейс', 'ритейл', 'розниц', 'товар',
		],
		'local_service' => [
			'local service', 'repair', 'cleaning', 'workshop',
			'локальный сервис', 'ремонт', 'уборк', 'мастер',
		],
	];

	private const GENERIC_PHRASES = [
		'теплая палитра натуральная фотография мягкий свет',
		'нейтральная палитра естественный свет реалистичные материалы',
		'нейтральная палитра lifestyle фото естественный свет',
		'современные реалистичные фотографии для сайта',
		'качественные красивые фотографии в едином стиле',
		'natural daylight realistic materials clean modern product presentation editorial landing page photography',
		'natural daylight realistic interiors',
		'warm palette natural photography soft light',
		'neutral palette natural light realistic materials',
		'modern realistic website photography',
	];

	private const GENERIC_MARKERS = [
		'натуральная фотография',
		'реалистичная фотография',
		'реалистичные фотографии',
		'реалистич',
		'натуральн',
		'естествен',
		'реалистичные материалы',
		'естественный свет',
		'мягкий свет',
		'теплая палитра',
		'нейтральная палитра',
		'качественные красивые',
		'natural photography',
		'realistic photography',
		'realistic',
		'natural',
		'daylight',
		'realistic materials',
		'natural daylight',
		'natural light',
		'soft light',
		'warm palette',
		'neutral palette',
		'clean modern',
	];

	private const AXIS_MARKERS = [
		'palette' => [
			'холод', 'тепл', 'нейтрал', 'приглуш', 'воздуш', 'насыщ', 'монохром', 'контраст', 'пастел',
			'cold', 'cool', 'warm', 'neutral', 'muted', 'airy', 'saturated', 'monochrome', 'contrast', 'pastel',
		],
		'light' => [
			'high key', 'low key', 'рассеян', 'боков', 'контров', 'направлен', 'студийн', 'тени', 'свет',
			'микроконтраст', 'shadow', 'light', 'backlight', 'side light', 'directional', 'studio', 'microcontrast',
		],
		'optics' => [
			'35mm', '50mm', 'широкий угол', 'широк', 'угол', 'telephoto', 'compression', 'глубин', 'фокус',
			'перспект', 'резк', 'wide angle', 'depth', 'focus', 'perspective', 'lens', 'sharp',
		],
		'finish' => [
			'editorial', 'cinematic', 'documentary', 'premium', 'tactile', 'clinical', 'energetic', 'calm',
			'glossy', 'matte', 'commercial', 'outdoor', 'architectural', 'пленоч', 'зерн', 'детализ',
			'коммерческ', 'глянц', 'матов', 'спокойн', 'энергич', 'архитектур',
		],
	];

	private const CONTENT_WORDS_EN = [
		'people', 'person', 'office', 'food', 'product', 'goods', 'interior', 'interiors', 'team', 'clinic',
		'restaurant', 'building', 'car', 'screen', 'interface', 'scene', 'scenes', 'material', 'materials',
		'room', 'rooms',
	];

	private const CONTENT_ROOTS_RU = [
		'люд', 'офис', 'ед', 'блюд', 'товар', 'продукт', 'интерьер', 'команд', 'клиник', 'ресторан',
		'здан', 'автомобил', 'экран', 'интерфейс', 'помещен', 'материал', 'сцен',
	];

	public static function normalize(string $brief): string
	{
		$brief = preg_replace('/\s+/u', ' ', $brief) ?: $brief;
		$brief = trim($brief);
		$brief = preg_replace('/[.。]+$/u', '', $brief) ?: $brief;
		$brief = trim($brief, " \t\n\r\0\x0B,;");

		if (mb_strlen($brief) > self::MAX_LENGTH)
		{
			$brief = mb_substr($brief, 0, self::MAX_LENGTH);
			$brief = trim($brief, " \t\n\r\0\x0B,;.");
		}

		return $brief;
	}

	public static function isGeneric(string $brief): bool
	{
		$brief = self::normalize($brief);
		if ($brief === '')
		{
			return true;
		}

		$normalized = self::normalizeForMatch($brief);
		if (self::containsGenericPhrase($normalized) || self::containsContentWord($normalized))
		{
			return true;
		}

		$axisCount = self::countStyleAxes($normalized);
		if ($axisCount < 2)
		{
			return true;
		}

		return $axisCount < 3 && self::containsAny($normalized, self::GENERIC_MARKERS);
	}

	public static function fallbackFromEnhanced(array $enhanced): string
	{
		$source = self::buildEnhancedText($enhanced);
		$normalized = self::normalizeForMatch($source);
		$isRussian = self::hasCyrillic($source);
		$fallbacks = $isRussian ? self::FALLBACK_RU : self::FALLBACK_EN;

		foreach (self::ARCHETYPE_KEYWORDS as $archetype => $keywords)
		{
			if (self::containsAny($normalized, $keywords))
			{
				return $fallbacks[$archetype] ?? $fallbacks['default'];
			}
		}

		return $fallbacks['default'];
	}

	public static function resolve(array $enhanced): string
	{
		$brief = self::normalize((string)($enhanced['imageStyleBrief'] ?? ''));
		if ($brief !== '' && !self::isGeneric($brief))
		{
			return $brief;
		}

		return self::fallbackFromEnhanced($enhanced);
	}

	private static function buildEnhancedText(array $enhanced): string
	{
		$parts = [];
		foreach ($enhanced as $value)
		{
			if (is_scalar($value))
			{
				$parts[] = (string)$value;
			}
		}

		return implode(' ', $parts);
	}

	private static function normalizeForMatch(string $text): string
	{
		$text = mb_strtolower($text);
		$text = str_replace('ё', 'е', $text);
		$text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;

		return trim($text);
	}

	private static function hasCyrillic(string $text): bool
	{
		return preg_match('/[А-Яа-яЁё]/u', $text) === 1;
	}

	private static function containsGenericPhrase(string $normalized): bool
	{
		foreach (self::GENERIC_PHRASES as $phrase)
		{
			if (mb_strpos($normalized, self::normalizeForMatch($phrase)) !== false)
			{
				return true;
			}
		}

		return false;
	}

	private static function containsContentWord(string $normalized): bool
	{
		$tokens = explode(' ', $normalized);
		foreach ($tokens as $token)
		{
			if (in_array($token, self::CONTENT_WORDS_EN, true))
			{
				return true;
			}

			foreach (self::CONTENT_ROOTS_RU as $root)
			{
				if (str_starts_with($token, $root))
				{
					return true;
				}
			}
		}

		return false;
	}

	private static function countStyleAxes(string $normalized): int
	{
		$count = 0;
		foreach (self::AXIS_MARKERS as $markers)
		{
			if (self::containsAny($normalized, $markers))
			{
				$count++;
			}
		}

		return $count;
	}

	private static function containsAny(string $normalized, array $markers): bool
	{
		foreach ($markers as $marker)
		{
			if (mb_strpos($normalized, self::normalizeForMatch((string)$marker)) !== false)
			{
				return true;
			}
		}

		return false;
	}
}

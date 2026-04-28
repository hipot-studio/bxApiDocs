<?php

namespace Bitrix\Crm\Import\Enum;

use Bitrix\Crm\Import\Contract\Enum\HasTitleInterface;

enum Encoding: string implements HasTitleInterface
{
	case ASCII = 'ascii';
	case UTF8 = 'UTF-8';
	case UTF16 = 'UTF-16';
	case WINDOWS_1251 = 'windows-1251';
	case WINDOWS_1252 = 'Windows-1252';
	case ISO_8859_1 = 'iso-8859-1';
	case ISO_8859_2 = 'iso-8859-2';
	case ISO_8859_3 = 'iso-8859-3';
	case ISO_8859_4 = 'iso-8859-4';
	case ISO_8859_5 = 'iso-8859-5';
	case ISO_8859_6 = 'iso-8859-6';
	case ISO_8859_7 = 'iso-8859-7';
	case ISO_8859_8 = 'iso-8859-8';
	case ISO_8859_9 = 'iso-8859-9';
	case ISO_8859_10 = 'iso-8859-10';
	case ISO_8859_13 = 'iso-8859-13';
	case ISO_8859_14 = 'iso-8859-14';
	case ISO_8859_15 = 'iso-8859-15';
	case KOI8_R = 'koi8-r';

	public function getTitle(): string
	{
		return match ($this) {
			self::ASCII => 'ASCII',
			self::UTF8 => 'UTF-8',
			self::UTF16 =>  'UTF-16',
			self::WINDOWS_1251 => 'Windows-1251',
			self::WINDOWS_1252 => 'Windows-1252',
			self::ISO_8859_1 => 'ISO-8859-1',
			self::ISO_8859_2 => 'ISO-8859-2',
			self::ISO_8859_3 => 'ISO-8859-3',
			self::ISO_8859_4 => 'ISO-8859-4',
			self::ISO_8859_5 => 'ISO-8859-5',
			self::ISO_8859_6 => 'ISO-8859-6',
			self::ISO_8859_7 => 'ISO-8859-7',
			self::ISO_8859_8 => 'ISO-8859-8',
			self::ISO_8859_9 => 'ISO-8859-9',
			self::ISO_8859_10 => 'ISO-8859-10',
			self::ISO_8859_13 => 'ISO-8859-13',
			self::ISO_8859_14 => 'ISO-8859-14',
			self::ISO_8859_15 => 'ISO-8859-15',
			self::KOI8_R => 'KOI8-R',
		};
	}

	public static function tryFromEncoding(mixed $value): ?self
	{
		foreach (self::cases() as $case)
		{
			if (mb_strtolower($case->value) === mb_strtolower($value))
			{
				return $case;
			}
		}

		return null;
	}

	public static function defaultByLanguage(?string $language): self
	{
		$language = mb_strtolower(trim($language));

		return match ($language) {
			'ru',
			'ua',
			'by',
			'kz' => self::WINDOWS_1251,

			'en',
			'de',
			'fr',
			'es',
			'it',
			'pt',
			'nl',
			'sv',
			'no',
			'da',
			'fi',
			'is' => self::WINDOWS_1252,

			'pl',
			'cs',
			'sk',
			'hu',
			'hr',
			'sl',
			'ro',
			'bg',
			'sq',
			'sr',
			'bs',
			'mk',
			'me' => self::ISO_8859_2,

			'lv',
			'lt',
			'et' => self::ISO_8859_4,

			'ar' => self::ISO_8859_6,

			'el' => self::ISO_8859_7,

			'he' => self::ISO_8859_8,

			'tr' => self::ISO_8859_9,

			'kk' => self::KOI8_R,

			default => self::UTF8,
		};
	}
}

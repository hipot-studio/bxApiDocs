<?php

namespace Bitrix\DocumentGenerator\Value;

use Bitrix\DocumentGenerator\Context;
use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\DocumentGenerator\DateShift;
use Bitrix\DocumentGenerator\Nameable;
use Bitrix\DocumentGenerator\Timezone;
use Bitrix\DocumentGenerator\Value;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type;

class DateTime extends Value implements Nameable
{
	private static ?\WeakMap $warningStates = null;

	/**
	 * DateTime constructor.
	 * @param $value
	 * @param array $options
	 */
	public function __construct($value, array $options = [])
	{
		$options = array_merge(static::getDefaultOptions(), $this->getOptions($options));
		$format = $options['format'];
		if (!$value instanceof Type\Date)
		{
			$value = $this->getFromString($value, $format);
		}
		parent::__construct($value, $options);
	}

	/**
	 * @param $string
	 * @param null $format
	 * @return Type\DateTime|null
	 */
	protected function getFromString($string, $format = null)
	{
		if(is_array($string) || is_object($string))
		{
			return null;
		}
		$value = Type\DateTime::tryParse($string);
		if(!$value instanceof Type\Date)
		{
			$value = Type\DateTime::tryParse($string, $format);
		}
		if(!$value instanceof Type\Date && Loader::includeModule('rest'))
		{
			$convertedDate = \CRestUtil::unConvertDateTime($string);
			if($convertedDate)
			{
				$value = Type\DateTime::tryParse($convertedDate);
			}
		}
		if(!$value)
		{
			$value = null;
		}

		return $value;
	}

	/**
	 * @param null $modifier
	 * @return string
	 */
	public function toString($modifier = null)
	{
		$date = $this->value;
		$options = $this->getOptions($modifier);
		$format = $options['format'];
		if ($date instanceof Type\DateTime)
		{
			$timestamp = $date->getTimestamp();
			if (Timezone::isEnabled())
			{
				$timezone = $this->resolveTimezone($options);
				$shift = $this->resolveShift($options, $date);
				$date = $shift?->applyTo($date, $timezone) ?? $date;
				$timestamp = Timezone::getTimestampForFormatting($date, $timezone);
			}

			return $this->formatDate($format, $timestamp);
		}
		if ($date instanceof Type\Date)
		{
			if (Timezone::isEnabled())
			{
				$shift = $this->resolveShift($options, $date);
				$date = $shift?->applyTo($date, $this->resolveTimezone($options)) ?? $date;
			}

			return $this->formatDate($format, $date->getTimestamp());
		}

		if(!$date)
		{
			$date = '';
		}
		return $date;
	}

	private function formatDate(string $format, int $timestamp): string
	{
		$interfaceLanguage = LANGUAGE_ID;
		$templateLanguage = DataProviderManager::getInstance()->getRegionLanguageId();
		if ($templateLanguage !== $interfaceLanguage)
		{
			Loc::setCurrentLang($templateLanguage);
		}
		$result = FormatDate($format, $timestamp);
		if ($templateLanguage !== $interfaceLanguage)
		{
			Loc::setCurrentLang($interfaceLanguage);
		}

		return $result;
	}

	private function resolveShift(array $options, Type\Date $date): ?DateShift
	{
		if (!array_key_exists('add', $options))
		{
			return null;
		}

		$expression = $options['add'];
		$shift = is_string($expression) ? DateShift::tryParse($expression) : null;
		if ($shift === null)
		{
			$this->logWarning(
				DataProviderManager::getInstance()->getContext(),
				'add.invalid',
				'Invalid datetime add modifier; value is not shifted.',
			);

			return null;
		}
		if (!$shift->isApplicableTo($date))
		{
			$this->logWarning(
				DataProviderManager::getInstance()->getContext(),
				'add.timeUnitForDate',
				'Time unit in datetime add modifier is not applicable to date; value is not shifted.',
			);

			return null;
		}

		return $shift;
	}

	private function resolveTimezone(array $options): string
	{
		$manager = DataProviderManager::getInstance();
		$fallback = $manager->getTimezone();
		if (!array_key_exists('timezone', $options))
		{
			return $fallback;
		}

		$timezone = $options['timezone'];
		if (!is_string($timezone) || !Timezone::isValid($timezone))
		{
			$message = $timezone === ''
				? 'Empty datetime timezone modifier; inherited timezone is used.'
				: 'Invalid datetime timezone modifier; inherited timezone is used.'
			;
			$this->logWarning(
				$manager->getContext(),
				$timezone === '' ? 'empty' : 'invalid',
				$message,
			);

			return $fallback;
		}

		return $timezone;
	}

	private function logWarning(Context $context, string $category, string $message): void
	{
		self::$warningStates ??= new \WeakMap();
		if (!isset(self::$warningStates[$context]))
		{
			self::$warningStates[$context] = [
				'logger' => (new LoggerFactory())->createById(
					'documentgenerator.Default',
					isCheckEnabledFromRegistry: false,
				),
				'warnings' => [],
			];
		}

		$state = self::$warningStates[$context];
		if (isset($state['warnings'][$category]))
		{
			return;
		}

		$state['warnings'][$category] = true;
		self::$warningStates[$context] = $state;
		$state['logger']->warning($message);
	}

	/**
	 * @return array
	 */
	protected static function getDefaultOptions()
	{
		return ['format' => Type\Date::getFormat(DataProviderManager::getInstance()->getCulture())];
	}

	/**
	 * @param string|array $modifier
	 * @return array
	 */
	public static function parseModifier($modifier)
	{
		if (is_array($modifier))
		{
			return $modifier;
		}
		if (is_object($modifier))
		{
			return [];
		}

		$modifier = (string)$modifier;
		if ($modifier === '')
		{
			return [];
		}
		$segments = explode(',', $modifier);
		$remainingSegments = [];
		$addCount = 0;
		$add = null;
		foreach ($segments as $segment)
		{
			$trimmedSegment = trim($segment);
			if (str_starts_with($trimmedSegment, 'add='))
			{
				$addCount++;
				$add = trim(substr($trimmedSegment, 4));
				continue;
			}

			$remainingSegments[] = $segment;
		}
		if ($addCount > 0)
		{
			$result = self::parseModifier(implode(',', $remainingSegments));
			$result['add'] = $addCount > 1 ? false : $add;

			return $result;
		}

		$lastCommaPosition = mb_strrpos($modifier, ',');
		$lastSegment = $lastCommaPosition === false
			? $modifier
			: mb_substr($modifier, $lastCommaPosition + 1)
		;
		$lastSegment = trim($lastSegment);
		if (!str_starts_with($lastSegment, 'timezone='))
		{
			if (str_contains($modifier, '=') && !str_contains($modifier, 'timezone='))
			{
				$firstSegment = mb_strstr($modifier, ',', true);
				$firstSegment = $firstSegment === false ? $modifier : $firstSegment;
				if (str_starts_with(trim($firstSegment), 'format='))
				{
					return self::parseFormatModifier($modifier);
				}

				return parent::parseModifier($modifier);
			}

			return ['format' => $modifier];
		}

		$timezone = trim(mb_substr($lastSegment, mb_strlen('timezone=')));
		$result = ['timezone' => $timezone];
		if ($lastCommaPosition !== false)
		{
			$format = rtrim(mb_substr($modifier, 0, $lastCommaPosition));
			if ($format !== '')
			{
				$firstSegment = mb_strstr($format, ',', true);
				$firstSegment = $firstSegment === false ? $format : $firstSegment;
				$isFormatModifier = str_starts_with(trim($firstSegment), 'format=');
				$result = str_contains($format, '=')
					? ($isFormatModifier ? self::parseFormatModifier($format) : parent::parseModifier($format))
					: ['format' => $format]
				;
				$result['timezone'] = $timezone;
			}
		}

		return $result;
	}

	private static function parseFormatModifier(string $modifier): array
	{
		$parts = explode(',', $modifier);
		$result = parent::parseModifier(array_shift($parts));
		$format = $result['format'] ?? '';

		foreach ($parts as $part)
		{
			if (str_contains($part, '='))
			{
				$result = array_merge($result, parent::parseModifier($part));
				continue;
			}

			$format .= ',' . $part;
		}

		$result['format'] = $format;

		return $result;
	}

	/**
	 * @return string
	 */
	public static function getLangName()
	{
		Loc::loadLanguageFile(__FILE__);
		return Loc::getMessage('DOCGEN_VALUE_DATETIME_TITLE');
	}
}

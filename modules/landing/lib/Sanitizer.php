<?php

namespace Bitrix\Landing;

use Bitrix\Main\Loader;
use Bitrix\Security\Filter;

class Sanitizer
{
	public const AVAILABLE_TEXT_FILTERS = [
		'sanitize' => 'sanitize',
		'reverseSanitize' => 'reverseSanitize',
		'noEmptyText' => 'noEmptyText',
	];
	private const DEFAULT_TEXT_FILTERS = [
		'sanitize' => 'sanitize',
		'reverseSanitize' => 'reverseSanitize',
	];

	private array $sanitizers = [];
	private array $filters = self::DEFAULT_TEXT_FILTERS;

	/**
	 * Disable some operations for text sanitize.
	 * See AVAILABLE_TEXT_FILTERS
	 * @param string $filter
	 * @return void
	 */
	public function disableTextFilter(string $filter): void
	{
		if (in_array($filter, self::AVAILABLE_TEXT_FILTERS, true))
		{
			unset($this->filters[$filter]);
		}
	}

	public function enableTextFilter(string $filter): void
	{
		if (in_array($filter, self::AVAILABLE_TEXT_FILTERS, true))
		{
			$this->filters[$filter] = self::AVAILABLE_TEXT_FILTERS[$filter];
		}
	}

	/**
	 * Check is filter enable
	 * @param string $filter
	 * @return bool
	 */
	private function checkFilter(string $filter): bool
	{
		return in_array($filter, $this->filters, true);
	}

	/**
	 * Sanitize bad value.
	 * @param string|array $text Bad value.
	 * @param bool &$bad Return true, if value is bad.
	 * @param string $splitter Splitter for bad content.
	 * @return string|array Good value.
	 */
	public function sanitizeText(string|array $text, bool &$bad = false, string $splitter = ' '): string|array
	{
		if (is_array($text))
		{
			return array_map(function ($val) use (&$bad, $splitter)
			{
				return $this->sanitizeText($val, $bad, $splitter);
			}, $text);
		}

		$needReverse = $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['reverseSanitize']);
		if ($needReverse)
		{
			// Restoring tags to prevent them from appearing after finish reverse
			$text = $this->reverseSanitizeText($text);
		}

		$needSanitize = $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['sanitize']);
		$sanitizer = $this->getSanitizer($splitter);
		if ($needSanitize && $sanitizer !== null)
		{
			if ($sanitizer->process($text))
			{
				$bad = true;
				$text = $sanitizer->getFilteredValue();
			}
		}

		if ($needReverse)
		{
			$text = $this->reverseSanitizeText($text);
		}

		if (
			$text === ''
			&& $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['noEmptyText'])
		)
		{
			$text = ' ';
		}

		return $text;
	}

	/**
	 * Replaces some specific for landing substitutions back after sanitize
	 * @param string $text
	 * @return string
	 */
	private function reverseSanitizeText(string $text): string
	{
		return str_replace(
			[' bxstyle="', '<sv g', '<sv g ', '<?', '?>', '<fo rm', '<fo rm '],
			[' style="', '<svg', '<svg ', '< ?', '? >', '<form', '<form '],
			$text
		);
	}

	private function getSanitizer(string $splitter): ?Filter\Auditor\Xss
	{
		$sanitizer = $this->sanitizers[$splitter] ?? null;
		if (
			$sanitizer === null
			&& Loader::includeModule('security')
		)
		{
			$sanitizer = new Filter\Auditor\Xss($splitter);
			$this->sanitizers[$splitter] = $sanitizer;
		}

		return $sanitizer;
	}

	public function sanitizeHrefTarget(string $target): string
	{
		$allowable = [
			'_self',
			'_blank',
			'_popup',
		];
		$default = '_self';
		$target = mb_strtolower(trim($target));

		return in_array($target, $allowable, true) ? $target : $default;
	}

	public function sanitizeNodeName(string $nodeName): string
	{
		$allowable = [
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'div',
			'p',
			'a',
			'span',
		];
		$default = 'div';
		$nodeName = mb_strtolower(trim($nodeName));

		return in_array($nodeName, $allowable, true) ? $nodeName : $default;
	}
}

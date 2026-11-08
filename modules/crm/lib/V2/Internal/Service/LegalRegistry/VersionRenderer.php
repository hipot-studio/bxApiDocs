<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Main\UserConsent\ConsentUrl;
use InvalidArgumentException;

final class VersionRenderer
{
	public function validateAgainstSchema(array $schema, array $substitutions): void
	{
		if (array_diff_key($substitutions, $schema) !== [] || array_diff_key($schema, $substitutions) !== [])
		{
			throw new InvalidArgumentException('Invalid substitutions.');
		}
		$names = [];
		foreach ($schema as $key => $type)
		{
			$lower = mb_strtolower((string)$key);
			if (!is_string($key) || $key === '' || isset($names[$lower])
				|| !in_array($type, ['string', 'integer', 'number', 'boolean', 'string[]'], true))
			{
				throw new InvalidArgumentException('Invalid substitution schema.');
			}
			$names[$lower] = true;
			$value = $substitutions[$key];
			$valid = $value === null || match ($type)
			{
				'string' => is_string($value),
				'integer' => is_int($value),
				'number' => is_int($value) || (is_float($value) && is_finite($value)),
				'boolean' => is_bool($value),
				'string[]' => is_array($value) && array_is_list($value)
					&& array_reduce($value, static fn(bool $ok, mixed $item): bool => $ok && is_string($item), true),
				default => false,
			};
			if (!$valid) { throw new InvalidArgumentException('Invalid substitutions.'); }
		}
		try { json_encode($substitutions, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); }
		catch (\JsonException) { throw new InvalidArgumentException('Invalid substitutions.'); }
	}

	public function validateContent(CatalogVersion $version): void
	{
		try
		{
			$schemaJson = json_encode((object)$version->substitutionSchema, JSON_THROW_ON_ERROR);
			json_encode(
				[$version->template, $version->label, $version->languageId, $version->displayUrl],
				JSON_THROW_ON_ERROR,
			);
		}
		catch (\JsonException) { throw new InvalidArgumentException('Invalid version encoding.'); }
		if (strlen($version->template) > 262144 || strlen($version->label) > 262144
			|| mb_strlen($version->languageId) > 10
			|| strlen($schemaJson) > 65536
			|| !hash_equals(hash('sha256', $version->template), $version->contentHash)
			|| ($version->displayUrl !== null && !ConsentUrl::isAllowed($version->displayUrl)))
		{
			throw new InvalidArgumentException('Invalid version content.');
		}
		foreach ([$version->template, $version->label] as $text)
		{
			if (preg_match('/<[A-Za-z!\/]/', $text) === 1 && self::sanitizeHtml($text, false) !== $text)
			{
				throw new InvalidArgumentException('Invalid version markup.');
			}
		}
		$nulls = array_fill_keys(array_keys($version->substitutionSchema), null);
		$this->validateAgainstSchema($version->substitutionSchema, $nulls);
		$this->replace($version->template, $version->substitutionSchema, $nulls);
		$this->replace($version->label, $version->substitutionSchema, $nulls);
	}

	public function render(CatalogVersion $version, array $substitutions): array
	{
		$this->validateContent($version);
		$this->validateAgainstSchema($version->substitutionSchema, $substitutions);
		$text = $this->replace($version->template, $version->substitutionSchema, $substitutions);
		$label = $this->replace($version->label, $version->substitutionSchema, $substitutions);
		if (strlen($text) > 262144 || strlen($label) > 262144)
		{
			throw new InvalidArgumentException('Invalid rendered content size.');
		}
		foreach ([$text, $label] as $markup)
		{
			if (preg_match('/<[A-Za-z!\/]/', $markup) === 1 && self::sanitizeHtml($markup, false) !== $markup)
			{
				throw new InvalidArgumentException('Invalid rendered markup.');
			}
		}
		$snapshot = [];
		foreach ($substitutions as $key => $value)
		{
			if (is_array($value))
			{
				$list = [];
				foreach ($value as $entry) { $list[] = $entry; }
				$snapshot[$key] = $list;
			}
			else { $snapshot[$key] = $value; }
		}

		return ['text' => $text, 'label' => $label, 'languageId' => $version->languageId,
			'substitutions' => $snapshot, 'textHash' => hash('sha256', $text)];
	}

	/** Only declared Main-style tokens are interpreted; unknown/literal percent text stays exact. */
	public function replace(string $template, array $schema, array $values): string
	{
		$this->validateAgainstSchema($schema, $values);
		$declared = [];
		foreach ($schema as $key => $type) { $declared[mb_strtolower($key)] = $values[$key]; }
		preg_match_all('/%(?:cut\.([A-Za-z0-9_.]+)\.(start|end)|([A-Za-z0-9_.]+))%/',
			$template, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$result = ''; $position = 0; $stack = []; $visible = true;
		foreach ($tokens as $token)
		{
			[$literal, $offset] = $token[0];
			if ($visible) { $result .= substr($template, $position, $offset - $position); }
			$position = $offset + strlen($literal);
			$key = $token[1][0] !== '' ? $token[1][0] : $token[3][0];
			if (!array_key_exists($key, $declared))
			{
				if ($visible) { $result .= $literal; }

				continue;
			}
			if ($token[1][0] !== '')
			{
				if ($token[2][0] === 'start')
				{
					$stack[] = [$key, $visible];
					$visible = $visible && !empty($declared[$key]);
				}
				else
				{
					$open = array_pop($stack);
					if ($open === null || $open[0] !== $key)
					{
						throw new InvalidArgumentException('Invalid conditional block.');
					}
					$visible = $open[1];
				}
			}
			elseif ($visible) { $result .= self::formatValue($declared[$key]); }
		}
		if ($stack !== []) { throw new InvalidArgumentException('Invalid conditional block.'); }
		if ($visible) { $result .= substr($template, $position); }

		return $result;
	}

	public static function sanitizeHtml(string $text, bool $doubleEncode = true): string
	{
		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_MIDDLE);
		$sanitizer->ApplyDoubleEncode($doubleEncode);
		$sanitizer->allowAttributes(['target' => ['tag' => static fn(string $tag): bool => $tag === 'a',
			'content' => static fn(string $tag): bool => true]]);

		return $sanitizer->sanitizeHtml($text);
	}

	private static function formatValue(mixed $value): string
	{
		if (is_array($value))
		{
			$lines = []; $count = count($value);
			foreach ($value as $index => $item) { $lines[] = '- ' . $item . ($index + 1 === $count ? '.' : ';'); }
			$text = implode("\n", $lines);
		}
		else { $text = $value === null ? '' : (string)$value; }

		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

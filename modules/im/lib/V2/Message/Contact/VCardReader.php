<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Text\Encoding;

final class VCardReader
{
	private const MAX_EMBEDDED_PHOTO_SIZE = 1024 * 1024;
	private const MAX_PHYSICAL_LINE_COUNT = 100000;
	private const PARSE_ERROR = 'VCARD_PARSE_ERROR';

	public function read(string $content): Result
	{
		$result = new Result();

		try
		{
			$records = $this->parse($content);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Invalid vCard content', self::PARSE_ERROR));
		}

		return $result->setResult($records);
	}

	private function parse(string $content): array
	{
		$records = [];
		$currentRecord = null;

		foreach ($this->unfoldLines($content) as $line)
		{
			if ($line === '')
			{
				continue;
			}

			$trimmedLine = trim($line);
			if (strcasecmp($trimmedLine, 'BEGIN:VCARD') === 0)
			{
				if ($currentRecord !== null)
				{
					throw new \UnexpectedValueException('Nested vCard record');
				}

				$currentRecord = $this->createEmptyRecord();

				continue;
			}

			if (strcasecmp($trimmedLine, 'END:VCARD') === 0)
			{
				if ($currentRecord === null)
				{
					throw new \UnexpectedValueException('Unexpected vCard end marker');
				}

				$records[] = $currentRecord;
				$currentRecord = null;

				continue;
			}

			if ($currentRecord === null)
			{
				throw new \UnexpectedValueException('Data outside a vCard record');
			}

			$separatorPosition = $this->findFirstUnescapedDelimiter($line, ':');
			if ($separatorPosition === null)
			{
				throw new \UnexpectedValueException('Invalid vCard property');
			}

			$propertyDefinition = substr($line, 0, $separatorPosition);
			$value = substr($line, $separatorPosition + 1);
			[$propertyName, $parameters] = $this->parsePropertyDefinition($propertyDefinition);

			switch ($propertyName)
			{
				case 'N':
					$nameParts = $this->splitUnescaped($this->decodeTextValue($value, $parameters), ';');
					$currentRecord['lastName'] ??= $this->normalizeText($nameParts[0] ?? '');
					$currentRecord['firstName'] ??= $this->normalizeText($nameParts[1] ?? '');

					break;

				case 'FN':
					$currentRecord['displayName'] ??= $this->normalizeText(
						$this->decodeTextValue($value, $parameters),
					);

					break;

				case 'ORG':
					$organizationParts = $this->splitUnescaped(
						$this->decodeTextValue($value, $parameters),
						';',
					);
					$currentRecord['organization'] ??= $this->normalizeText($organizationParts[0] ?? '');

					break;

				case 'TEL':
					$phone = $this->normalizeText($this->decodeTextValue($value, $parameters));
					if ($phone !== null)
					{
						$phone = preg_replace('/\Atel:/i', '', $phone, 1);
						if ($phone !== null && $phone !== '')
						{
							$currentRecord['phones'][] = $phone;
						}
					}

					break;

				case 'PHOTO':
					if (!array_key_exists('embeddedPhoto', $currentRecord))
					{
						$photo = $this->readEmbeddedPhoto($value, $parameters);
						if ($photo !== null)
						{
							$currentRecord['embeddedPhoto'] = $photo;
						}
					}

					break;
			}
		}

		if ($currentRecord !== null || empty($records))
		{
			throw new \UnexpectedValueException('Unclosed or missing vCard record');
		}

		return $records;
	}

	private function unfoldLines(string $content): \Generator
	{
		$length = strlen($content);
		$offset = str_starts_with($content, "\xEF\xBB\xBF") ? 3 : 0;
		$physicalLineCount = 0;
		$logicalLineParts = [];
		$isQuotedPrintable = false;

		while ($offset < $length)
		{
			if (++$physicalLineCount > self::MAX_PHYSICAL_LINE_COUNT)
			{
				throw new \UnexpectedValueException('Too many vCard lines');
			}

			$lineLength = strcspn($content, "\r\n", $offset);
			$line = substr($content, $offset, $lineLength);
			$offset += $lineLength;
			if ($offset < $length)
			{
				if ($content[$offset] === "\r" && $offset + 1 < $length && $content[$offset + 1] === "\n")
				{
					$offset += 2;
				}
				else
				{
					$offset++;
				}
			}

			$isFolded = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
			if (empty($logicalLineParts))
			{
				if ($isFolded)
				{
					throw new \UnexpectedValueException('Invalid folded line');
				}

				$logicalLineParts[] = $line;
				$isQuotedPrintable = $this->isQuotedPrintableProperty($line);

				continue;
			}

			$lastPartIndex = array_key_last($logicalLineParts);
			$isSoftBreak = $isQuotedPrintable && str_ends_with($logicalLineParts[$lastPartIndex], '=');
			if ($isSoftBreak)
			{
				$logicalLineParts[$lastPartIndex] = substr($logicalLineParts[$lastPartIndex], 0, -1);
				$logicalLineParts[] = $this->stripFoldingPrefix($line);

				continue;
			}

			if ($isFolded)
			{
				$logicalLineParts[] = $this->stripFoldingPrefix($line);

				continue;
			}

			yield implode('', $logicalLineParts);
			$logicalLineParts = [$line];
			$isQuotedPrintable = $this->isQuotedPrintableProperty($line);
		}

		if (!empty($logicalLineParts))
		{
			yield implode('', $logicalLineParts);
		}
	}

	private function isQuotedPrintableProperty(string $line): bool
	{
		$separatorPosition = $this->findFirstUnescapedDelimiter($line, ':');
		if ($separatorPosition === null)
		{
			return false;
		}

		$definition = substr($line, 0, $separatorPosition);

		return preg_match(
			'/(?:^|;)ENCODING\s*=\s*"?QUOTED-PRINTABLE"?(?:;|$)/iD',
			$definition,
		) === 1;
	}

	private function stripFoldingPrefix(string $line): string
	{
		if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))
		{
			return substr($line, 1);
		}

		return $line;
	}

	private function createEmptyRecord(): array
	{
		return [
			'firstName' => null,
			'lastName' => null,
			'displayName' => null,
			'organization' => null,
			'phones' => [],
		];
	}

	private function parsePropertyDefinition(string $definition): array
	{
		$parts = $this->splitUnescaped($definition, ';');
		$propertyName = array_shift($parts) ?? '';
		if (preg_match('/\Aitem[0-9]+\.(.+)\z/iD', $propertyName, $matches) === 1)
		{
			$propertyName = $matches[1];
		}

		$parameters = [];
		foreach ($parts as $parameter)
		{
			$separatorPosition = strpos($parameter, '=');
			if ($separatorPosition === false)
			{
				continue;
			}

			$name = strtoupper(trim(substr($parameter, 0, $separatorPosition)));
			if ($name === '')
			{
				continue;
			}

			$rawValues = explode(',', substr($parameter, $separatorPosition + 1));
			foreach ($rawValues as $rawValue)
			{
				$parameters[$name][] = strtoupper(trim($rawValue, " \t\n\r\0\x0B\""));
			}
		}

		return [strtoupper(trim($propertyName)), $parameters];
	}

	private function readEmbeddedPhoto(string $value, array $parameters): ?string
	{
		if (in_array('URI', $parameters['VALUE'] ?? [], true))
		{
			return null;
		}

		$value = trim($value);
		if (preg_match('/\Ahttps?:\/\//i', $value) === 1)
		{
			return null;
		}

		$encodedPhoto = null;
		if (preg_match('/\Adata:[^,]*;base64,(.*)\z/isD', $value, $matches) === 1)
		{
			$encodedPhoto = $matches[1];
		}
		elseif (
			in_array('B', $parameters['ENCODING'] ?? [], true)
			|| in_array('BASE64', $parameters['ENCODING'] ?? [], true)
		)
		{
			$encodedPhoto = $value;
		}

		if ($encodedPhoto === null || $encodedPhoto === '' || strlen($encodedPhoto) % 4 !== 0)
		{
			return null;
		}

		$maxEncodedLength = (int)ceil(self::MAX_EMBEDDED_PHOTO_SIZE / 3) * 4;
		if (
			strlen($encodedPhoto) > $maxEncodedLength
			|| preg_match(
				'/\A(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?\z/D',
				$encodedPhoto,
			) !== 1
		)
		{
			return null;
		}

		$decodedPhoto = base64_decode($encodedPhoto, true);
		if (
			$decodedPhoto === false
			|| $decodedPhoto === ''
			|| strlen($decodedPhoto) > self::MAX_EMBEDDED_PHOTO_SIZE
		)
		{
			return null;
		}

		return $decodedPhoto;
	}

	private function decodeTextValue(string $value, array $parameters): string
	{
		$encodings = array_values(array_unique(array_filter($parameters['ENCODING'] ?? [])));
		if (count($encodings) > 1)
		{
			throw new \UnexpectedValueException('Ambiguous vCard text encoding');
		}

		$encoding = $encodings[0] ?? '';
		if ($encoding === 'QUOTED-PRINTABLE')
		{
			$value = quoted_printable_decode($value);
		}
		elseif ($encoding !== '' && $encoding !== '7BIT' && $encoding !== '8BIT')
		{
			throw new \UnexpectedValueException('Unsupported vCard text encoding');
		}

		$charsets = array_values(array_unique(array_filter($parameters['CHARSET'] ?? [])));
		if (count($charsets) > 1)
		{
			throw new \UnexpectedValueException('Ambiguous vCard text charset');
		}

		$charset = $charsets[0] ?? '';
		if ($charset !== '' && strcasecmp($charset, SITE_CHARSET) !== 0)
		{
			$value = Encoding::convertEncoding($value, $charset, SITE_CHARSET);
		}

		return $value;
	}

	private function normalizeText(string $value): ?string
	{
		$value = trim($this->unescapeText($value));

		return $value !== '' ? $value : null;
	}

	private function unescapeText(string $value): string
	{
		$result = '';
		$length = strlen($value);

		for ($index = 0; $index < $length; $index++)
		{
			$character = $value[$index];
			if ($character !== '\\' || $index + 1 >= $length)
			{
				$result .= $character;

				continue;
			}

			$escapedCharacter = $value[++$index];
			$result .= match ($escapedCharacter)
			{
				'n', 'N' => "\n",
				'\\', ',', ';', ':' => $escapedCharacter,
				default => '\\' . $escapedCharacter,
			};
		}

		return $result;
	}

	private function splitUnescaped(string $value, string $delimiter): array
	{
		$parts = [];
		$partStart = 0;
		$length = strlen($value);
		$escaped = false;

		for ($index = 0; $index < $length; $index++)
		{
			$character = $value[$index];
			if (!$escaped && $character === $delimiter)
			{
				$parts[] = substr($value, $partStart, $index - $partStart);
				$partStart = $index + 1;
			}

			if (!$escaped && $character === '\\')
			{
				$escaped = true;
			}
			else
			{
				$escaped = false;
			}
		}

		$parts[] = substr($value, $partStart);

		return $parts;
	}

	private function findFirstUnescapedDelimiter(string $value, string $delimiter): ?int
	{
		$escaped = false;
		$length = strlen($value);

		for ($index = 0; $index < $length; $index++)
		{
			$character = $value[$index];
			if (!$escaped && $character === $delimiter)
			{
				return $index;
			}

			if (!$escaped && $character === '\\')
			{
				$escaped = true;
			}
			else
			{
				$escaped = false;
			}
		}

		return null;
	}
}

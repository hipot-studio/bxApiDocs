<?php

namespace Bitrix\AI\Engine;

enum ResponseFormat: string
{
	case DEFAULT = 'default';
	case JSON = 'json';
	case MARKDOWN = 'markdown';
	case PLAINTEXT = 'plaintext';
	case HTML = 'html';
	case BBCODE = 'bbcode';

	public static function fromString(?string $value = 'default'): self
	{
		return match (strtolower($value))
		{
			'json' => self::JSON,
			'markdown' => self::MARKDOWN,
			'plaintext' => self::PLAINTEXT,
			'html' => self::HTML,
			'bbcode' => self::BBCODE,
			default => self::DEFAULT,
		};
	}
}

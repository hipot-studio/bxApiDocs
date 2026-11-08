<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

/**
 * Published list of the system values a template may reference: what the entity selector offers and
 * where the readable form of an expression takes the name of such a reference from.
 *
 * The record of AI agents stands apart because only the selector hides it behind the feature.
 * getLabel() answers over both sets: the name of a reference already written into a title must not
 * depend on whether the feature is on right now.
 */
final class SystemValueCatalog
{
	/**
	 * Module file the phrases of the names belong to: the entity selector
	 * {@see \Bitrix\Bizproc\Integration\UI\EntitySelector\SystemProvider} published the list first and
	 * keeps its language file.
	 */
	private const LABEL_PHRASES_FILE = '/lib/Integration/UI/EntitySelector/SystemProvider.php';

	public const OBJECT_SYSTEM = 'System';
	public const OBJECT_WORKFLOW = 'Workflow';
	public const OBJECT_USER = 'User';
	public const OBJECT_TEMPLATE = 'Template';

	/** [object, field, label phrase key, value type] */
	private const SYSTEM_VALUES = [
		[self::OBJECT_WORKFLOW, 'ID', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_WORKFLOW_ID', FieldType::STRING],
		[self::OBJECT_WORKFLOW, 'TemplateId', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_WORKFLOW_TEMPLATE_ID', FieldType::INT],
		[self::OBJECT_TEMPLATE, 'TargetUser', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_TARGET_USER', FieldType::USER],
		[self::OBJECT_USER, 'ID', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_USER_ID', FieldType::USER],
		[self::OBJECT_SYSTEM, 'Now', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_NOW', FieldType::DATETIME],
		[self::OBJECT_SYSTEM, 'NowLocal', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_NOW_LOCAL', FieldType::DATETIME],
		[self::OBJECT_SYSTEM, 'Date', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_DATE', FieldType::DATE],
		[self::OBJECT_SYSTEM, 'Eol', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_EOL', FieldType::STRING],
		[self::OBJECT_SYSTEM, 'HostUrl', 'BIZPROC_ENTITY_SELECTOR_SYSTEM_HOST_URL', FieldType::STRING],
	];

	private const AI_AGENTS_SYSTEM_VALUE = [
		self::OBJECT_WORKFLOW,
		'TemplateActivatedBy',
		'BIZPROC_ENTITY_SELECTOR_SYSTEM_TEMPLATE_ACTIVATED_BY',
		FieldType::USER,
	];

	/**
	 * Object and field of every published value => the phrase its name comes from, assembled out of both
	 * sets once per process.
	 *
	 * @var array<string, string>
	 */
	private static array $labelPhraseKeys = [];

	/** @var array<string, true> languages the file of the phrases is registered with Loc for */
	private static array $languagesWithPhrases = [];

	/**
	 * Values every template has, in the order they are published.
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function getSystemValues(): array
	{
		return self::SYSTEM_VALUES;
	}

	/**
	 * The value only an available AI agents feature adds to the list.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 */
	public static function getAiAgentsSystemValue(): array
	{
		return self::AI_AGENTS_SYSTEM_VALUE;
	}

	/**
	 * Name of one system value, matched by the object and the field exactly as an expression writes
	 * them, or null when the pair stands in no set.
	 */
	public static function getLabel(string $object, string $field): ?string
	{
		$labelPhraseKey = self::getLabelPhraseKeys()[$object . '|' . $field] ?? null;

		return $labelPhraseKey === null ? null : self::readLabel($labelPhraseKey);
	}

	/** @return array<string, string> */
	private static function getLabelPhraseKeys(): array
	{
		if (self::$labelPhraseKeys === [])
		{
			foreach ([...self::SYSTEM_VALUES, self::AI_AGENTS_SYSTEM_VALUE] as [$object, $field, $labelPhraseKey])
			{
				self::$labelPhraseKeys[$object . '|' . $field] = $labelPhraseKey;
			}
		}

		return self::$labelPhraseKeys;
	}

	/** Registered once per language: registering costs a realpath() and loads the file for that language whole. */
	private static function readLabel(string $labelPhraseKey): string
	{
		$language = Loc::getCurrentLang();
		if (!isset(self::$languagesWithPhrases[$language]))
		{
			Loc::loadMessages(self::getLabelPhrasesFile());
			self::$languagesWithPhrases[$language] = true;
		}

		return (string)Loc::getMessage($labelPhraseKey);
	}

	/**
	 * Loc resolves phrases by the file that owns them, and a file that is gone means no phrases at all
	 * and no word about it - hence the check: a move of the owner has to break the names loudly.
	 */
	private static function getLabelPhrasesFile(): string
	{
		$file = self::getModuleDirectory() . self::LABEL_PHRASES_FILE;
		if (!is_file($file))
		{
			throw new SystemException('Phrases of the system values are gone from ' . self::LABEL_PHRASES_FILE);
		}

		return $file;
	}

	/** This file stands at <module>/lib/Workflow/Template. */
	private static function getModuleDirectory(): string
	{
		return dirname(__DIR__, 3);
	}
}

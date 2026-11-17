<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SectionSettings
{
	public const DEFAULT_GROUP_BY = 'none';
	public const DEFAULT_SORT_BY = 'deadline';
	public const DEFAULT_SORT_DIRECTION = 'asc';

	public const ERROR_VALUE_INVALID = 'SETTINGS_VALUE_INVALID';
	public const ERROR_OPTION_UNAVAILABLE = 'SETTINGS_OPTION_UNAVAILABLE';

	private const GROUP_BY_CAPABILITIES = [
		'none' => true,
		'project' => true,
	];

	private const SORT_BY_CAPABILITIES = [
		'my' => false,
		'activity' => false,
		'name' => true,
		'deadline' => true,
		// the "Repeat condition" column: ordered by the regularity type rank, not by a field
		'regularity' => true,
		'originator' => true,
		'assignee' => true,
		'priority' => true,
		// creation date is ordered by template id - the section list has no own created field
		'created' => true,
		'changed' => false,
	];

	private const SORT_DIRECTION_CAPABILITIES = [
		'asc' => true,
		'desc' => true,
	];

	/**
	 * Direction a sorting falls back to while the user has not picked one - the order the section
	 * had before the direction became selectable. An explicit choice overrides it.
	 */
	private const SORT_BY_DEFAULT_DIRECTION = [
		'priority' => 'desc',
		'created' => 'desc',
	];

	private const IMPORT_ACTION_READY = false;
	private const EXPORT_ACTION_READY = false;

	private function __construct(
		public readonly string $groupBy,
		public readonly string $sortBy,
		public readonly string $sortDirection,
	)
	{
	}

	public static function createDefault(): self
	{
		return new self(
			groupBy: self::DEFAULT_GROUP_BY,
			sortBy: self::DEFAULT_SORT_BY,
			sortDirection: self::resolveDefaultDirection(self::DEFAULT_SORT_BY),
		);
	}

	public static function createFromStored(mixed $stored): self
	{
		if (!is_array($stored))
		{
			return self::createDefault();
		}

		$sortBy = self::normalizeStoredValue(
			$stored['sortBy'] ?? null,
			self::SORT_BY_CAPABILITIES,
			self::DEFAULT_SORT_BY,
		);

		return new self(
			groupBy: self::normalizeStoredValue(
				$stored['groupBy'] ?? null,
				self::GROUP_BY_CAPABILITIES,
				self::DEFAULT_GROUP_BY,
			),
			sortBy: $sortBy,
			sortDirection: self::normalizeStoredValue(
				$stored['sortDirection'] ?? null,
				self::SORT_DIRECTION_CAPABILITIES,
				self::resolveDefaultDirection($sortBy),
			),
		);
	}

	public static function createFromInput(array $input): Result
	{
		$result = new Result();

		$groupByResult = self::validateInputValue(
			$input['groupBy'] ?? null,
			'groupBy',
			self::GROUP_BY_CAPABILITIES,
		);
		if (!$groupByResult->isSuccess())
		{
			return $result->addErrors($groupByResult->getErrors());
		}

		$sortByResult = self::validateInputValue(
			$input['sortBy'] ?? null,
			'sortBy',
			self::SORT_BY_CAPABILITIES,
		);
		if (!$sortByResult->isSuccess())
		{
			return $result->addErrors($sortByResult->getErrors());
		}

		$sortDirectionResult = self::validateInputDirection(
			$input['sortDirection'] ?? null,
			$sortByResult->getData()['value'],
		);
		if (!$sortDirectionResult->isSuccess())
		{
			return $result->addErrors($sortDirectionResult->getErrors());
		}

		if (array_key_exists('calendarSync', $input) && $input['calendarSync'] !== null)
		{
			return $result->addError(
				new Error(
					'Section setting option is unavailable.',
					self::ERROR_OPTION_UNAVAILABLE,
					['field' => 'calendarSync'],
				),
			);
		}

		$result->setData([
			'settings' => new self(
				groupBy: $groupByResult->getData()['value'],
				sortBy: $sortByResult->getData()['value'],
				sortDirection: $sortDirectionResult->getData()['value'],
			),
		]);

		return $result;
	}

	public static function getGroupByCapabilities(): array
	{
		return self::GROUP_BY_CAPABILITIES;
	}

	public static function getSortByCapabilities(): array
	{
		return self::SORT_BY_CAPABILITIES;
	}

	public static function getSortDirectionCapabilities(): array
	{
		return self::SORT_DIRECTION_CAPABILITIES;
	}

	public function toDto(
		bool $hasImportAccess = false,
		bool $hasExportAccess = false,
		?string $importUrl = null,
	): array
	{
		$canImport = self::IMPORT_ACTION_READY && $hasImportAccess;
		$canExport = self::EXPORT_ACTION_READY && $hasExportAccess;

		return [
			'groupBy' => $this->groupBy,
			'sortBy' => $this->sortBy,
			'sortDirection' => $this->sortDirection,
			'calendarSync' => null,
			'capabilities' => [
				'groupBy' => self::GROUP_BY_CAPABILITIES,
				'sortBy' => self::SORT_BY_CAPABILITIES,
				'sortDirection' => self::SORT_DIRECTION_CAPABILITIES,
				'canImport' => $canImport,
				'canExport' => $canExport,
				'canSync' => false,
			],
			'importUrl' => $canImport ? $importUrl : null,
		];
	}

	private static function resolveDefaultDirection(string $sortBy): string
	{
		return self::SORT_BY_DEFAULT_DIRECTION[$sortBy] ?? self::DEFAULT_SORT_DIRECTION;
	}

	/**
	 * A payload without the direction comes from a client that does not know the option yet
	 * (API-RS1 stays backward compatible): the sorting keeps its own default instead of being
	 * rejected as an invalid value.
	 */
	private static function validateInputDirection(mixed $value, string $sortBy): Result
	{
		if ($value === null)
		{
			return (new Result())->setData(['value' => self::resolveDefaultDirection($sortBy)]);
		}

		return self::validateInputValue($value, 'sortDirection', self::SORT_DIRECTION_CAPABILITIES);
	}

	private static function normalizeStoredValue(mixed $value, array $capabilities, string $default): string
	{
		if (!is_string($value) || !($capabilities[$value] ?? false))
		{
			return $default;
		}

		return $value;
	}

	private static function validateInputValue(mixed $value, string $field, array $capabilities): Result
	{
		$result = new Result();
		if (!is_string($value) || !array_key_exists($value, $capabilities))
		{
			return $result->addError(
				new Error(
					'Invalid section setting value.',
					self::ERROR_VALUE_INVALID,
					['field' => $field],
				),
			);
		}

		if (!$capabilities[$value])
		{
			return $result->addError(
				new Error(
					'Section setting option is unavailable.',
					self::ERROR_OPTION_UNAVAILABLE,
					['field' => $field],
				),
			);
		}

		$result->setData(['value' => $value]);

		return $result;
	}
}

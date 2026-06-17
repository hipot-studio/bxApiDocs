<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Public\Command\FullReport\AddCommand;

final class Create implements Arrayable
{
	use MapTypeTrait;
	use PayloadListMapTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly int $userId,
		public readonly ?string $reportText = null,
		public readonly ?string $plansText = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $tasks = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $events = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $files = null,
		public readonly bool $autoFillDailyReports = true,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
	)
	{
	}

	public function getId(): mixed
	{
		return null;
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			userId: self::mapInteger($props, 'userId'),
			reportText: self::mapString($props, 'reportText'),
			plansText: self::mapString($props, 'plansText'),
			tasks: self::mapTasks($props),
			events: self::mapEvents($props),
			files: self::mapFiles($props),
			autoFillDailyReports: self::mapBool($props, 'autoFillDailyReports', true) ?? true,
			dateFrom: self::mapInteger($props, 'dateFrom'),
			dateTo: self::mapInteger($props, 'dateTo'),
		);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'reportText' => $this->reportText,
			'plansText' => $this->plansText,
			'tasks' => $this->tasks,
			'events' => $this->events,
			'files' => $this->files,
			'autoFillDailyReports' => $this->autoFillDailyReports,
			'dateFrom' => $this->dateFrom,
			'dateTo' => $this->dateTo,
		];
	}

	public function toCommand(): AddCommand
	{
		return new AddCommand(
			userId: $this->userId,
			reportText: $this->reportText,
			plansText: $this->plansText,
			tasks: $this->tasks,
			events: $this->events,
			files: $this->files,
			autoFillDailyReports: $this->autoFillDailyReports,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
		);
	}
}

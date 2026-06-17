<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportMark;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Public\Command\FullReport\UpdateCommand;

final class Update implements Arrayable
{
	use MapTypeTrait;
	use PayloadListMapTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly int $reportId,
		public readonly ?string $reportText = null,
		public readonly ?string $plansText = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $tasks = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $events = null,
		/** @var list<array<string, int|string|float|bool|null>>|null */
		public readonly ?array $files = null,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
		public readonly ?string $mark = null,
	)
	{
	}

	public function getId(): mixed
	{
		return $this->reportId;
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			reportId: self::mapInteger($props, 'reportId'),
			reportText: self::mapString($props, 'reportText'),
			plansText: self::mapString($props, 'plansText'),
			tasks: self::mapTasks($props),
			events: self::mapEvents($props),
			files: self::mapFiles($props),
			dateFrom: self::mapInteger($props, 'dateFrom'),
			dateTo: self::mapInteger($props, 'dateTo'),
			mark: self::mapMark($props),
		);
	}

	private static function mapMark(array $props): ?string
	{
		$mark = self::mapString($props, 'mark');
		if ($mark === null)
		{
			return null;
		}

		return FullReportMark::tryFrom($mark)?->value;
	}

	public function toArray(): array
	{
		return [
			'reportId' => $this->reportId,
			'reportText' => $this->reportText,
			'plansText' => $this->plansText,
			'tasks' => $this->tasks,
			'events' => $this->events,
			'files' => $this->files,
			'dateFrom' => $this->dateFrom,
			'dateTo' => $this->dateTo,
			'mark' => $this->mark,
		];
	}

	public function toCommand(): UpdateCommand
	{
		return new UpdateCommand(
			reportId: $this->reportId,
			reportText: $this->reportText,
			plansText: $this->plansText,
			tasks: $this->tasks,
			events: $this->events,
			files: $this->files,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
			mark: $this->mark,
		);
	}
}

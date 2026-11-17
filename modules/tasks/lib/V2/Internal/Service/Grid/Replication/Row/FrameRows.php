<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row;

use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\RowPayloadFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\ScheduleCellFieldAssembler;

/**
 * Строки-рамки грида раздела «Регулярные задачи»: заголовки периодов (первая строка
 * страницы) и «Загруженность» (последняя). Обрамляют страницу вне пагинации.
 *
 * Сырые значения колонок здесь — маркеры для сборщиков ячеек. SCHEDULE отдаёт якорь
 * периодов/загруженности ({@see ScheduleCellFieldAssembler}). В колонке NAME строка
 * «периоды» пустая (`null`), а «загруженность» несёт якорь подписи агрегата
 * ({@see RowPayloadFieldAssembler::WORKLOAD_LABEL}): заголовок «Загруженность» и сводка
 * рендерятся в колонке «Регулярная задача», полоса чисел — в колонке SCHEDULE.
 *
 * `ID` — нечисловые «часовые» (не пересекаются с templateId): по ним {@see ReplicationRows}
 * снимает со строк-рамок выбор и DnD.
 */
final class FrameRows
{
	public const PERIODS_ID = 'replication-periods';
	public const WORKLOAD_ID = 'replication-workload';

	/**
	 * @return array<string, mixed>
	 */
	public static function periods(): array
	{
		return [
			'ID' => self::PERIODS_ID,
			ReplicationProvider::NAME_COLUMN => null,
			ReplicationProvider::SCHEDULE_COLUMN => ScheduleCellFieldAssembler::PERIODS,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function workload(): array
	{
		return [
			'ID' => self::WORKLOAD_ID,
			ReplicationProvider::NAME_COLUMN => RowPayloadFieldAssembler::WORKLOAD_LABEL,
			ReplicationProvider::SCHEDULE_COLUMN => ScheduleCellFieldAssembler::WORKLOAD,
		];
	}

	public static function isFrame(array $rawRow): bool
	{
		return in_array($rawRow['ID'] ?? null, [self::PERIODS_ID, self::WORKLOAD_ID], true);
	}
}

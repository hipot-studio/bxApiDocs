<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Dto\Replication;

/**
 * DTO-S3 (элемент) — постановка в agenda раздела «Регулярные задачи» (P3.T1).
 *
 * Состоявшаяся постановка (`created`) → ссылка на созданную задачу (`taskId`);
 * будущая (`plan`) → на родительское правило (`templateId`, `taskId = null`);
 * `error` — неуспешная постановка. `when` — timestamp постановки (в пояс
 * просматривающего на рендере, AC-S-062).
 *
 * `completed`/`assignee` значимы только для факта (`created`): фронт разводит
 * завершённую/созданную флагом `completed`, а не отдельным wire-статусом; для
 * `plan`/`error` оба поля — `null`.
 */
class AgendaItemDto
{
	public const STATUS_CREATED = 'created';
	public const STATUS_PLAN = 'plan';
	public const STATUS_ERROR = 'error';

	/**
	 * @param array{id:int, name:string, avatar:?string}|null $assignee
	 */
	public function __construct(
		public readonly int $when,
		public readonly int $templateId,
		public readonly string $name,
		public readonly string $status,
		public readonly ?int $taskId = null,
		public readonly ?bool $completed = null,
		public readonly ?array $assignee = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'when' => $this->when,
			'templateId' => $this->templateId,
			'name' => $this->name,
			'status' => $this->status,
			'taskId' => $this->taskId,
			'completed' => $this->completed,
			'assignee' => $this->assignee,
		];
	}
}

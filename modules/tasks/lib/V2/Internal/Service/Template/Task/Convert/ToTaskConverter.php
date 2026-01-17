<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field\ConvertDatePlan;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field\ConvertDeadline;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field\ConvertPipeline;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field\ConvertResponsibles;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field\ConvertSubTasks;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Trait\ConfigTrait;

class ToTaskConverter
{
	use ConfigTrait;

	public function __invoke(Entity\Template $template): Entity\Task
	{
		$taskBuilder = new TaskBuilder(
			$this->createInitialTask($template),
		);

		$this->applyConversions($template, $taskBuilder);

		return $taskBuilder->build();
	}

	private function createInitialTask(Entity\Template $template): Entity\Task
	{
		$fields = $template->toArray();
		$fields['id'] = null;
		$fields['rights'] = null;
		$fields['replicate'] = false;

		return Entity\Task::mapFromArray($fields);
	}

	private function applyConversions(Entity\Template $template, TaskBuilder $taskBuilder): void
	{
		$pipeline = new ConvertPipeline($this->config, [
			ConvertDeadline::class,
			ConvertDatePlan::class,
			ConvertSubTasks::class,
			ConvertResponsibles::class,
		]);

		$pipeline($template, $taskBuilder);
	}
}

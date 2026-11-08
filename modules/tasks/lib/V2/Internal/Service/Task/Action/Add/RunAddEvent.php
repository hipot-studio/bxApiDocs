<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Util;
use Exception;

class RunAddEvent
{
	use ConfigTrait;

	public function __invoke(array $fields)
	{
		$parameters = $this->buildEventParameters();

		try
		{
			foreach (GetModuleEvents('tasks', 'OnTaskAdd', true) as $arEvent)
			{
				ExecuteModuleEventEx($arEvent, [$fields['ID'], &$fields, $parameters]);
			}
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);
			Util::log($exception);
		}

		return $fields;
	}

	/**
	 * Builds the parameters passed to OnTaskAdd listeners.
	 *
	 * Extracted so the MANUAL_DEADLINE resolution can be unit-tested
	 * without firing the whole event bus.
	 */
	public function buildEventParameters(): array
	{
		return [
			'USER_ID' => $this->config->getUserId(),
			// System-creation markers (agent replication, bizproc robots): they run
			// on regular HTTP hits, so listeners cannot infer the source from the
			// request context alone.
			'FROM_AGENT' => $this->config->isFromAgent(),
			'FROM_WORKFLOW' => $this->config->isFromWorkFlow(),
			// Whether the user set the deadline manually. Primary source: the explicit
			// client marker (getManualDeadline) sent by the V2 create form. When the
			// caller did not report it (null), fall back to the heuristic captured in
			// PrepareFields before the pipeline could substitute the user's default
			// auto deadline, so the onboarding counter does not mistake an
			// accepted auto deadline for a manual one. KNOWN LIMITATION: the mobile
			// app, the legacy CTaskItem path and REST do not send the marker, so they
			// keep relying on the DEADLINE heuristic (their behaviour is unchanged).
			'MANUAL_DEADLINE' => $this->config->getManualDeadline()
				?? $this->config->getRuntime()->isClientDeadlineProvided(),
		];
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\Operation\GenerateClientSummary;
use Bitrix\Crm\Integration\AI\Operation\Scenario;

/**
 * The summary of a client: one step, asked for by hand from the card and answered into the chat.
 *
 * A scenario of its own because the inherited `full` one is the chain of call processing, and its
 * switch is the four settings of that chain. A portal that has turned all four off still shows the
 * button of the summary, and the launch would refuse it with a slider about a setting the summary has
 * nothing to do with.
 *
 * `isEnabled()` is always true: whether a summary may be asked for at all is decided by
 * {@see \Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability} before the job
 * is created, and a second switch here could only disagree with it.
 *
 * One step means a terminal one: `NEXT_TYPE_ID` of the job is 0, and the callback of the queue
 * continues no chain after it (@see \Bitrix\Crm\Copilot\Pipeline\ScenarioResolver::resolve()).
 *
 * The job is built by {@see \Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryJobLauncher}, which
 * has the chat of the answer; {@see \Bitrix\Crm\Copilot\Pipeline\StepFactory} knows no step of this
 * scenario and builds none, so the timeline of an activity cannot launch it.
 */
final class ClientSummaryScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::CLIENT_SUMMARY_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			GenerateClientSummary::class,
		];
	}

	public function isEnabled(): bool
	{
		return true;
	}
}

<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AiSummary;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Trigger\Service\SummaryTriggerGateway;
use CCrmOwnerType;

/**
 * The one measure of whether the summary of a CRM entity is there for a user at all, kept as two
 * questions: what the user is granted, and what the portal has turned on.
 *
 * It is declared here and asked by everyone the catalogue of MCP looks at: the tool, the set the tool
 * belongs to and the agent that uses it. The measure lives apart from all three on purpose. The index
 * of tools asks the tool itself and nothing above it, while a set is asked only when the client names
 * it, so a measure kept in the set alone would never reach the catalogue; and the same rule written
 * out three times would part ways at the first change.
 *
 * The two questions are answered apart because the two callers are owed different things. A point of
 * entry that draws a surface of the summary asks them together, by {@see isAvailableFor()} - the
 * buttons of the headers of a contact and a company, the field of the client of a deal and the tab of
 * the summary in the window of a call: a surface leading to a refusal is worse than no surface at all.
 * The chat asks {@see isGrantedTo()} alone, so that the tool stays listed on a portal that turned the
 * summary off and answers the model in words - a tool that disappeared instead would leave the model
 * to improvise a summary out of whatever else it can reach.
 *
 * A third question stands beside the two and not inside them: {@see isLaunchable()} adds the engine of
 * the triggers of the assistant, which is what a surface outside a chat opens the summary through. It
 * is asked by what goes that way alone - the button of the header of a card, the hint pointing at it
 * and the guard of the launch behind them - because the tool and the agent are already inside a chat,
 * and the tab of the window of a call never starts a trigger at all. The guard asks it for the same
 * reason the button does and one of its own: a card opened before an admin closed the engine still
 * carries the button, and the press is owed the reason instead of the generic failure of the engine.
 *
 * The order inside {@see isAvailableFor()} is not free: the grant is asked first, because reading the
 * switch builds the manager of the settings and sends the load event around the portal, and that costs
 * more than every other check of the measure together.
 *
 * The class is left open to inheritance for its tests alone: both the availability of AI and the switch
 * of the portal are answered by static calls of classes that cannot be substituted otherwise.
 *
 * @internal
 */
class SummaryAvailability
{
	/**
	 * The entity types a summary is answered for. A user working with one of them is enough: the union,
	 * not the intersection, or a role that keeps to deals alone would never see the summary at all.
	 */
	private const ENTITY_TYPE_IDS = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
		CCrmOwnerType::Deal,
	];

	private ?SummaryTriggerGateway $triggerGateway = null;

	/**
	 * Both questions at once - what every point of entry drawing a surface of the summary is owed.
	 */
	public function isAvailableFor(int $userId): bool
	{
		return $this->isGrantedTo($userId) && $this->isEnabledOnPortal();
	}

	/**
	 * The measure, and the engine the summary is opened through on top of it.
	 *
	 * The engine belongs to the assistant and not to CRM, and a launch runs into it before a chat exists
	 * ({@see \Bitrix\AiAssistant\Trigger\Service\TriggerExecutor::execute()}) - its switch, which is off
	 * by default, and the row of the trigger, which a portal gets on a schedule. A surface drawn without
	 * asking would answer every press with the generic failure of the engine.
	 *
	 * The measure is asked first: it is the answer for most portals and it keeps the engine unqueried.
	 *
	 * The type of the card names the trigger to ask about. It is left out by the one caller that has no
	 * card - the hint of the onboarding, built from the templates of both cards - and the engine then
	 * answers about the user instead ({@see SummaryTriggerGateway::isLaunchableFor()}).
	 */
	public function isLaunchable(int $userId, ?int $entityTypeId = null): bool
	{
		return $this->isAvailableFor($userId) && $this->isTriggerLaunchableFor($userId, $entityTypeId);
	}

	/**
	 * The grant alone: the key of the rollout, the availability of AI and the right to read elements of
	 * at least one of the three types the summary is answered for.
	 *
	 * A user refused here is refused the feature itself, and nothing the admin of the portal can flip
	 * changes that - which is why the switch is no part of this answer.
	 */
	public function isGrantedTo(int $userId): bool
	{
		if (!Feature::enabled(AiSummary::class))
		{
			return false;
		}

		if (!$this->isAiAvailableFor($userId))
		{
			return false;
		}

		$entityTypePermissions = Container::getInstance()->getUserPermissions($userId)->entityType();
		foreach (self::ENTITY_TYPE_IDS as $entityTypeId)
		{
			if ($entityTypePermissions->canReadItems($entityTypeId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The switch an admin flips on the settings page of the CoPilot in CRM, asked of the one facade that
	 * owns the global settings of CRM. It is answered for the portal and not for a user, so it takes no
	 * identifier.
	 *
	 * No cache is kept here: the facade builds the manager of the settings once per request and hands
	 * the same answer back afterwards.
	 */
	public function isEnabledOnPortal(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::ClientSummary);
	}

	/**
	 * The one place the legacy availability of AI in CRM is asked about. The feature has no measure of
	 * its own and builds none.
	 *
	 * `isAiCallProcessingEnabled()` is asked, not the plain `isAvailable()`, because it also asks the
	 * tariff feature `crm_copilot` - and so do both callbacks of the queue
	 * ({@see \Bitrix\Crm\Integration\AI\EventHandler::onQueueJobExecute()},
	 * {@see \Bitrix\Crm\Integration\AI\EventHandler::onQueueJobFail()}). Without the feature the
	 * answer of a job that has already been sent to the model never reaches the chat, and the person is
	 * left with the typing indicator up for good. A tool that could only end that way must not be
	 * offered at all.
	 */
	protected function isAiAvailableFor(int $userId): bool
	{
		return AIManager::isAiCallProcessingEnabled() && AIManager::isAILicenceAccepted($userId);
	}

	/**
	 * The engine is asked of the one gateway CRM starts the triggers through, so the measure and the
	 * launch read the same answer and no class of the assistant is reached for from here.
	 *
	 * The gateway is kept rather than built per question: it remembers what the engine answered within
	 * this request, and one card asks the same question twice - once for the button of its header and
	 * once for the hint pointing at that button.
	 */
	protected function isTriggerLaunchableFor(int $userId, ?int $entityTypeId): bool
	{
		return ($this->triggerGateway ??= new SummaryTriggerGateway())->isLaunchableFor($userId, $entityTypeId);
	}
}

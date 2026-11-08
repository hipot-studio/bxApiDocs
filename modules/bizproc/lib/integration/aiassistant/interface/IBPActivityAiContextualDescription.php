<?php

namespace Bitrix\Bizproc\Integration\AiAssistant\Interface;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;

/**
 * A hand-written description whose schema depends on the values the block is already pinned to: the columns of
 * the storage a write node addresses are the keys of its value map, and they are known only once the storage is
 * named. The context is the very one the properties map of an activity is built with
 * ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription()}), so one activity reads one context
 * whichever half of its description asks for it.
 *
 * Declared as an extension of {@see IBPActivityAiDescription} rather than as a wider signature on it: a
 * description that answers the same schema for every context must keep working untouched, and the contextless
 * method stays the single entry every other caller uses.
 */
interface IBPActivityAiContextualDescription extends IBPActivityAiDescription
{
	/**
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 * @param array $context ['Properties' => array<string, mixed>] - the values the block is pinned to, empty
	 *        when the block is described on its own
	 */
	public function getAiDescribedSettingsInContext(array $documentType, array $context): SettingCollection;
}

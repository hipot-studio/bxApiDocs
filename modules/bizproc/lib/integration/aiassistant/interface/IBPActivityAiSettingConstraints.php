<?php

namespace Bitrix\Bizproc\Integration\AiAssistant\Interface;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraintCollection;

/**
 * Declared beside {@see IBPActivityAiDescription} rather than inside it: an activity description that has no
 * cross-setting rules must keep working untouched, and an interface cannot add a method without breaking it.
 */
interface IBPActivityAiSettingConstraints
{
	/**
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 */
	public function getAiSettingConstraints(array $documentType): SettingConstraintCollection;
}

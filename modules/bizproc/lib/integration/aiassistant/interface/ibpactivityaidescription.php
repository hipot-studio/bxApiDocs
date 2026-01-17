<?php

namespace Bitrix\Bizproc\Integration\AiAssistant\Interface;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;

interface IBPActivityAiDescription
{
	/**
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 *
	 * @return SettingCollection
	 */
	public function getAiDescribedSettings(array $documentType): SettingCollection;
}
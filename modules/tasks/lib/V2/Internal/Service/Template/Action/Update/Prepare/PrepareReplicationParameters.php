<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Replication\Template\Option\Options;

class PrepareReplicationParameters implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		$isReplicateParamsChanged = $this->isReplicateParametersChanged($fullTemplateData, $fields);

		if ((int)($template['BASE_TEMPLATE_ID'] ?? null) > 0)
		{
			unset($fields['REPLICATE'], $fields['PARENT_ID']);
		}

		return $fields;
	}

	private function isReplicateParametersChanged(array $template, array $fields): bool
	{
		if ((int)($template['BASE_TEMPLATE_ID'] ?? null) > 0)
		{
			return false;
		}

		if (isset($fields['REPLICATE']) && ($template['REPLICATE'] ?? null) !== $fields['REPLICATE'])
		{
			return true;
		}

		if (!isset($fields['REPLICATE_PARAMS']))
		{
			return false;
		}

		$before = $template['REPLICATE_PARAMS'] ?? null;
		$before = is_string($before) ? unserialize($before, ['allowed_classes' => false]) : $before;

		$after = $fields['REPLICATE_PARAMS'];
		$after = is_string($after) ? unserialize($after, ['allowed_classes' => false]) : $after;
		if (!is_array($before))
		{
			return is_array($after);
		}

		return !Options::isNewAndCurrentOptionsEquals($before, $after);
	}
}

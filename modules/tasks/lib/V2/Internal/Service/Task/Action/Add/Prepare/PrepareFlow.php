<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare;

use Bitrix\Tasks\Control\Handler\Exception\TaskFieldValidateException;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\Flow\Control\Task\Exception\FlowTaskException;
use Bitrix\Tasks\Flow\Control\Task\Field\FlowFieldHandler;
use Bitrix\Tasks\Flow\FlowFeature;
use Bitrix\Tasks\Flow\Provider\Exception\FlowNotFoundException;

class PrepareFlow implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(array $fields): array
	{
		if ($this->skipModifyByFlow($fields))
		{
			return $fields;
		}

		$flowId = (int)($fields['FLOW_ID'] ?? 0);
		$handler = new FlowFieldHandler($flowId, $this->config->getUserId());

		$skipTimeZoneFields = $this->config->getSkipTimeZoneFields();

		try
		{
			$handler->modify($fields, $skipTimeZoneFields);
		}
		catch (FlowTaskException|FlowNotFoundException $e)
		{
			throw new TaskFieldValidateException($e->getMessage());
		}

		$this->config->setSkipTimeZoneFields($skipTimeZoneFields);

		return $fields;
	}

	protected function skipModifyByFlow(array $fields): bool
	{
		if (!FlowFeature::isFeatureEnabled() || !FlowFeature::isOn())
		{
			return true;
		}

		if (isset($fields['FLOW_ID']) && (int)$fields['FLOW_ID'] === 0)
		{
			return true;
		}

		return empty($fields['FLOW_ID']);
	}
}
<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required;

use Bitrix\Crm\V2\Public\Entity\Field\Requirement\SetInput;
use Bitrix\Main\HttpRequest;

final class SetRequest extends WriteRequest
{
	public ?array $requiredStageIds = null;

	private bool $hasRequiredStageIds = false;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		$request->hasRequiredStageIds = array_key_exists('requiredStageIds', $httpRequest->getJsonList()->getValues());

		return $request;
	}

	public function hasRequiredStageIds(): bool
	{
		return $this->hasRequiredStageIds;
	}

	public function toSetInput(): SetInput
	{
		return $this->hasRequiredStageIds
			? SetInput::withRequiredStageIds($this->requiredStageIds)
			: SetInput::withoutRequiredStageIds();
	}

	protected static function validateInput(array $input): void
	{
		if (!array_key_exists('requiredStageIds', $input))
		{
			return;
		}

		$stageIds = $input['requiredStageIds'];
		if (!is_array($stageIds) || !array_is_list($stageIds))
		{
			throw self::validationError('requiredStageIds must be an array of strings.', 'requiredStageIds');
		}
		foreach ($stageIds as $index => $stageId)
		{
			if (!is_string($stageId) || $stageId === '')
			{
				throw self::validationError(
					'Required stage ID must be a non-empty string.',
					'requiredStageIds[' . $index . ']',
				);
			}
		}
	}
}

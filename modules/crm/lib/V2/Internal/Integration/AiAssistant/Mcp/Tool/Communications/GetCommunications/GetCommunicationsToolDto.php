<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Communications\GetCommunications;

use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetCommunicationsToolDto extends AbstractToolDto
{
	/**
	 * The wire names of the items a history of conversations is answered for. The list is the single
	 * source of the enum of the input schema as well, so a type the tool does not answer for cannot
	 * reach the service through a schema and a validator that disagree.
	 *
	 * The names and the case of them repeat the tool of the summary word for word: two tools of one set
	 * calling the same thing differently cost the model mistakes it has no way of catching. That they
	 * still agree is held by a case of `GetCommunicationsToolTest`.
	 */
	public const ENTITY_TYPES = ['contact', 'company', 'deal'];

	public ?string $entityType = null;
	public ?int $entityId = null;
	public ?int $itemsLimit = null;

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityType'),
			new StringField($this, 'entityType'),
			new EnumField($this, 'entityType', self::ENTITY_TYPES),

			new RequiredField($this, 'entityId'),
			// min: 0 means "value > 0" - IntegerField compares strictly
			new IntegerField($this, 'entityId', min: 0),
		];

		// the number of conversations is optional, and a number above the ceiling is cut down to it by
		// the service instead of being refused here: the ceiling is a budget of the answer and not a
		// rule of the input
		if (array_key_exists('itemsLimit', $fields) && $fields['itemsLimit'] !== null)
		{
			$validators[] = new IntegerField($this, 'itemsLimit', min: 0);
		}

		return $validators;
	}
}

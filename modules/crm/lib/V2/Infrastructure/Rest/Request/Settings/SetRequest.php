<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Settings;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class SetRequest extends Request
{
	#[NotEmpty]
	#[Description('Stable setting identifier returned by crm.settings.list or crm.settings.get.')]
	public string $id;

	#[Description(
		'Required JSON value matching meta.schema of the selected setting. Do not stringify booleans, numbers, '
		. 'arrays or objects. For object settings, provide the complete value.',
	)]
	public mixed $value = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		$request = parent::create($httpRequest, $dtoClass, $options);
		$input = $httpRequest->getJsonList()->getValues();
		if (!array_key_exists('value', $input))
		{
			throw new RequiredFieldInRequestException('value');
		}

		$request->value = $input['value'];

		return $request;
	}
}

<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Type\BlockCode;

class EmployeeDynamicPlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	private readonly FieldService $fieldService;

	public function __construct(?FieldService $fieldService = null)
	{
		$this->fieldService = $fieldService ?? Container::instance()->getDocumentFieldService();
	}

	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		return NameHelper::create(
			BlockCode::EMPLOYEE_DYNAMIC,
			$fieldType,
			$party,
			$fieldCode,
		);
	}

	/**
	 * Raw user field types by the code create() puts into the placeholder name.
	 *
	 * The name carries the type resolveFieldType() converted the field to, and the conversion turns a
	 * url into a plain string, so a restriction that judges a url reads the type from here instead.
	 * The codes of this source keep the name of their user field, which no other source of the list
	 * produces (MemberDynamicFieldInfoProvider::isFieldCodeMemberDynamicField).
	 *
	 * @param array<int, array<string, mixed>> $fields descriptors of the fields list
	 * @return array<string, string>
	 */
	public static function collectRawFieldTypes(array $fields): array
	{
		$rawFieldTypes = [];

		foreach ($fields as $field)
		{
			$fieldCode = (string)($field['name'] ?? '');
			if ($fieldCode === '')
			{
				continue;
			}

			$rawFieldTypes[$fieldCode] = (string)($field['type'] ?? '');
		}

		return $rawFieldTypes;
	}

	protected function resolveFieldType(string $fieldCode, string $descriptorType): string
	{
		return $this->fieldService->convertUserFieldType($descriptorType);
	}
}

<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Type\BlockCode;

class UserFieldsPlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	public const USER_FIELD_CODE_PREFIX = 'USER_';
	private readonly FieldService $fieldService;
	private readonly ProfileProvider $profileProvider;

	public function __construct(
		?FieldService $fieldService = null,
		?ProfileProvider $profileProvider = null,
	)
	{
		$container = Container::instance();
		$this->fieldService = $fieldService ?? $container->getDocumentFieldService();
		$this->profileProvider = $profileProvider ?? $container->getServiceProfileProvider();
	}

	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		$blockCode = $party === 1
			? BlockCode::B2E_MY_REFERENCE
			: BlockCode::B2E_REFERENCE
		;

		return NameHelper::create(
			$blockCode,
			$fieldType,
			$party,
			self::USER_FIELD_CODE_PREFIX . $fieldCode,
		);
	}

	/**
	 * Raw user field types by the code create() puts into the placeholder name.
	 *
	 * The name carries the type resolveFieldType() converted the field to, and the conversion turns a
	 * url into a plain string, so a restriction that judges a url reads the type from here instead.
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

			$rawFieldTypes[self::USER_FIELD_CODE_PREFIX . $fieldCode] = (string)($field['type'] ?? '');
		}

		return $rawFieldTypes;
	}

	/**
	 * The descriptor of the fields list carries the raw user field type, while the meaning of a legal
	 * field is known to the profile provider, so the type is taken from the field description.
	 */
	protected function resolveFieldType(string $fieldCode, string $descriptorType): string
	{
		$fieldDescription = $this->profileProvider->getDescriptionByFieldName($fieldCode);

		return $this->fieldService->resolveProfileFieldType(
			$fieldCode,
			(string)($fieldDescription['type'] ?? ''),
		);
	}
}

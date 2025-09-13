<?php

namespace Bitrix\Mobile\Feedback;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use CBitrix24;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ModuleManager;

class FeedbackFormProvider
{
	public const ALLOWED_HIDDEN_FIELDS = [
		'c_email' => FILTER_VALIDATE_EMAIL,
		'c_name' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'from_domain' => FILTER_VALIDATE_URL,
		'b24_plan' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'sender_page' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'os_phone' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'app_version' => FILTER_VALIDATE_INT,
		'back_version' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'b24_type' => FILTER_VALIDATE_BOOLEAN,
		'os_version' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'region_model' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'phone_model' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
	];

	public const FORMS = [
		'copilotRoles' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formData' => [
					'data-b24-form' => 'inline/746/we50kv',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_746.js',
				],
			],
			'de' => [
				'portalZones' => ['de'],
				'formData' => [
					'data-b24-form' => 'inline/742/vqqxgr',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_742.js',
				],
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formData' => [
					'data-b24-form' => 'inline/744/nz3zig',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_744.js',
				],
			],
			'es' => [
				'portalZones' => ['es'],
				'formData' => [
					'data-b24-form' => 'inline/738/77ui4p',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_738.js',
				],
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formData' => [
					'data-b24-form' => 'inline/740/obza3e',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_740.js',
				],
			],
		],
		'appFeedbackLight' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formData' => [
					'data-b24-form' => 'inline/586/nkupl5',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_586.js',
					'formId' => 586,
				],
			],
			'de' => [
				'portalZones' => ['de'],
				'formData' => [
					'data-b24-form' => 'inline/802/lgshoi',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_802.js',
					'formId' => 802,
				],
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formData' => [
					'data-b24-form' => 'inline/806/4lxtzv',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_806.js',
					'formId' => 806,
				],
			],
			'es' => [
				'portalZones' => ['es'],
				'formData' => [
					'data-b24-form' => 'inline/804/6vz7b5',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_804.js',
					'formId' => 804,
				],
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formData' => [
					'data-b24-form' => 'inline/582/dyd2ut',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_582.js',
					'formId' => 582,
				],
			],
		],
		'appFeedbackDark' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formData' => [
					'data-b24-form' => 'inline/810/z0e29c',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_810.js',
					'formId' => 810,
				],
			],
			'de' => [
				'portalZones' => ['de'],
				'formData' => [
					'data-b24-form' => 'inline/812/n27x9c',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_812.js',
					'formId' => 812,
				],
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formData' => [
					'data-b24-form' => 'inline/816/v35xqq',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_816.js',
					'formId' => 816,
				],
			],
			'es' => [
				'portalZones' => ['es'],
				'formData' => [
					'data-b24-form' => 'inline/814/30eaek',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_814.js',
					'formId' => 814,
				],
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formData' => [
					'data-b24-form' => 'inline/808/8al21c',
					'uri' => 'https://cdn.bitrix24.com/b5309667/crm/form/loader_808.js',
					'formId' => 808,
				],
			],
		],
	];

	/**
	 * @throws LoaderException
	 */
	public static function getFormData(string $formId): array|null
	{
		$targetForm = self::FORMS[$formId];
		if (!empty($targetForm) && is_array($targetForm))
		{
			$portalZone = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: "en";
			$defaultFormData = null;
			foreach ($targetForm as $portalZonesData)
			{
				if (in_array($portalZone, $portalZonesData['portalZones']))
				{
					return $portalZonesData['formData'];
				}

				if ($portalZonesData['isDefault'] === true)
				{
					$defaultFormData = $portalZonesData['formData'];
				}
			}

			return $defaultFormData;
		}

		return null;
	}

	/**
	 * @throws LoaderException
	 * @throws \JsonException
	 */
	public static function getHiddenFieldsParams(?string $hiddenFields): ?array
	{
		$userFields = self::getUserFields();
		$systemFields = self::getPortalFields();

		if ($hiddenFields === null)
		{
			return array_merge($userFields, $systemFields);
		}

		$filteredFields = self::filterHiddenFields($hiddenFields);

		return array_merge($userFields, $systemFields, $filteredFields);
	}

	private static function getUserFields(): array
	{
		return [
			'c_name' => CurrentUser::get()->getFullName(),
			'c_email' => CurrentUser::get()->getEmail(),
		];
	}

	/**
	 * @throws LoaderException
	 */
	private static function getPortalFields(): array
	{
		$result = [];
		if (Loader::includeModule('bitrix24'))
		{
			$result['b24_type'] = true;
			$result['b24_plan'] = CBitrix24::getLicenseType();
		}
		else
		{
			$result['b24_type'] = false;
		}

		$result['mobile_module_ver'] = (string)ModuleManager::getVersion('mobile');

		return $result;
	}

	/**
	 * @throws \JsonException
	 */
	private static function filterHiddenFields(string $hiddenFields): array
	{
		$formValues = json_decode($hiddenFields, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($formValues))
		{
			return [];
		}

		$fieldValues = filter_var_array($formValues, self::ALLOWED_HIDDEN_FIELDS);

		if ($fieldValues === null)
		{
			return [];
		}

		$fieldValues = array_filter($fieldValues, static fn ($value) => $value !== null && $value !== false);

		if (isset($fieldValues['app_version']))
		{
			$fieldValues['app_version'] = (int)$fieldValues['app_version'];
		}

		return $fieldValues;
	}
}

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
		'contextId' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'message' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'sending_time' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
		'chat_id' => FILTER_VALIDATE_INT,
		'message_id' => FILTER_VALIDATE_INT,
		'user_id' => FILTER_VALIDATE_INT,
	];

	/**
	 * Region entries of the mobile feedback forms. Zone and language selection is delegated to
	 * ui\Form\FeedbackForm, which falls back to the en zone outside CIS and to ru inside it, so
	 * the isDefault flags below only declare which entry is meant to be the west default.
	 */
	public const FORMS = [
		'copilotRoles' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formId' => 746,
				'sec' => 'we50kv',
				'lang' => 'ru',
			],
			'de' => [
				'portalZones' => ['de'],
				'formId' => 742,
				'sec' => 'vqqxgr',
				'lang' => 'de',
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formId' => 744,
				'sec' => 'nz3zig',
				'lang' => 'br',
			],
			'es' => [
				'portalZones' => ['es'],
				'formId' => 738,
				'sec' => '77ui4p',
				'lang' => 'es',
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formId' => 740,
				'sec' => 'obza3e',
				'lang' => 'en',
			],
		],
		'aiAssistant' => [
			'ru-kz-by-uz' => [
				'portalZones' => ['ru', 'kz', 'by', 'uz'],
				'formId' => 2982,
				'sec' => 'vqmcxn',
				'lang' => 'ru',
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formId' => 840,
				'sec' => 'ufjnte',
				'lang' => 'br',
			],
			'es' => [
				'portalZones' => ['es'],
				'formId' => 838,
				'sec' => 'm82wkx',
				'lang' => 'es',
			],
			'de' => [
				'portalZones' => ['de'],
				'formId' => 836,
				'sec' => 'frcsm3',
				'lang' => 'de',
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formId' => 834,
				'sec' => 'qnauno',
				'lang' => 'en',
			],
		],
		'aiAssistantV2' => [
			'ru-kz-by-uz' => [
				'portalZones' => ['ru', 'kz', 'by', 'uz'],
				'formId' => 2982,
				'sec' => 'vqmcxn',
				'lang' => 'ru',
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formId' => 834,
				'sec' => 'qnauno',
				'lang' => 'en',
			],
		],
		'integrationConsultLt100' => [
			'ru' => [
				'portalZones' => ['ru'],
				'portalUri' => 'https://bitrix24.team',
				'formId' => 3636,
				'sec' => 'gsx2bc',
				'lang' => 'ru',
			],
		],
		'integrationConsultGt100' => [
			'ru' => [
				'portalZones' => ['ru'],
				'portalUri' => 'https://bitrix24.team',
				'formId' => 3631,
				'sec' => 'hoesxl',
				'lang' => 'ru',
			],
		],
		'appFeedbackLight' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formId' => 586,
				'sec' => 'nkupl5',
				'lang' => 'ru',
			],
			'de' => [
				'portalZones' => ['de'],
				'formId' => 802,
				'sec' => 'lgshoi',
				'lang' => 'de',
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formId' => 806,
				'sec' => '4lxtzv',
				'lang' => 'br',
			],
			'es' => [
				'portalZones' => ['es'],
				'formId' => 804,
				'sec' => '6vz7b5',
				'lang' => 'es',
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formId' => 582,
				'sec' => 'dyd2ut',
				'lang' => 'en',
			],
		],
		'appFeedbackDark' => [
			'ru-by-kz' => [
				'portalZones' => ['ru', 'by', 'kz'],
				'formId' => 810,
				'sec' => 'z0e29c',
				'lang' => 'ru',
			],
			'de' => [
				'portalZones' => ['de'],
				'formId' => 812,
				'sec' => 'n27x9c',
				'lang' => 'de',
			],
			'com.br' => [
				'portalZones' => ['com.br'],
				'formId' => 816,
				'sec' => 'v35xqq',
				'lang' => 'br',
			],
			'es' => [
				'portalZones' => ['es'],
				'formId' => 814,
				'sec' => '30eaek',
				'lang' => 'es',
			],
			'en' => [
				'isDefault' => true,
				'portalZones' => ['en'],
				'formId' => 808,
				'sec' => '8al21c',
				'lang' => 'en',
			],
		],
	];

	public const FORM_INTEGRATOR_REQUEST = 'integratorPartnerRequest';
	public const FORM_INTEGRATOR_FEEDBACK = 'integratorFeedback';
	public const FORM_INTEGRATOR_REFUSAL = 'integratorRefusal';

	/**
	 * Partner feedback forms are resolved dynamically from ui\FormProvider (the single
	 * source of truth for region-specific id/sec) instead of being duplicated in FORMS.
	 */
	private const PARTNER_FORM_RESOLVERS = [
		self::FORM_INTEGRATOR_REQUEST => 'getPartnerFormList',
		self::FORM_INTEGRATOR_FEEDBACK => 'getPartnerFeedbackFormList',
		self::FORM_INTEGRATOR_REFUSAL => 'getPartnerRefusalFormList',
	];

	private static array $partnerRegistry = [];

	public static function getFormConfig(string $formId): array
	{
		return self::buildWebviewConfig(self::resolveRegistry($formId));
	}

	/**
	 * Returns the region entry of the form the webview will actually render. The choice is made by
	 * ui\Form\FeedbackForm, the same mechanism bitrix:ui.feedback.form uses over the very config from
	 * getFormConfig(), so the form id handed to the page can not drift from the rendered one.
	 */
	public static function getFormData(string $formId): array|null
	{
		$registry = self::resolveRegistry($formId);

		$currentForm = self::resolveCurrentForm($formId, self::buildWebviewConfig($registry));
		if ($currentForm === null)
		{
			return null;
		}

		return self::findRegionData($registry, $currentForm);
	}

	/**
	 * @return array[] region entries of the form, empty when the form id is unknown
	 */
	private static function resolveRegistry(string $formId): array
	{
		$staticForm = self::FORMS[$formId] ?? null;
		if (is_array($staticForm))
		{
			return $staticForm;
		}

		return isset(self::PARTNER_FORM_RESOLVERS[$formId])
			? self::buildPartnerRegistry($formId)
			: []
		;
	}

	/**
	 * Reshapes the ui\FormProvider partner list into region entries, so partner forms travel the
	 * same path as FORMS and need no special case of their own.
	 */
	private static function buildPartnerRegistry(string $formId): array
	{
		if (isset(self::$partnerRegistry[$formId]))
		{
			return self::$partnerRegistry[$formId];
		}

		if (!Loader::includeModule('ui'))
		{
			return [];
		}

		$resolver = self::PARTNER_FORM_RESOLVERS[$formId];
		// Partner forms are hosted on the partner portal, not on the default feedback domain.
		$portalUri = (new \Bitrix\UI\Form\UrlProvider())->getPartnerPortalUrl();

		$entries = [];
		foreach ((new \Bitrix\UI\Form\FormProvider())->$resolver() as $form)
		{
			$entries[] = [
				'portalZones' => $form['zones'] ?? [],
				'formId' => $form['id'] ?? null,
				'lang' => $form['lang'] ?? null,
				'sec' => $form['sec'] ?? null,
				'portalUri' => $portalUri,
			];
		}

		self::$partnerRegistry[$formId] = $entries;

		return $entries;
	}

	private static function buildWebviewConfig(array $registry): array
	{
		$result = [];
		foreach ($registry as $regionData)
		{
			if (empty($regionData['portalZones'])
				|| empty($regionData['formId'])
				|| empty($regionData['lang'])
				|| empty($regionData['sec']))
			{
				continue;
			}

			$result[] = [
				'zones' => $regionData['portalZones'],
				'id' => (int)$regionData['formId'],
				'lang' => $regionData['lang'],
				'sec' => $regionData['sec'],
			];
		}

		return $result;
	}

	private static function resolveCurrentForm(string $formId, array $forms): ?array
	{
		if ($forms === [] || !Loader::includeModule('ui'))
		{
			return null;
		}

		$feedbackForm = new \Bitrix\UI\Form\FeedbackForm($formId);
		$feedbackForm->setFormParams($forms);
		$currentForm = $feedbackForm->getCurrentForm();

		return empty($currentForm['id']) ? null : $currentForm;
	}

	private static function findRegionData(array $registry, array $currentForm): ?array
	{
		foreach ($registry as $regionData)
		{
			if ((int)($regionData['formId'] ?? 0) === (int)$currentForm['id']
				&& (string)($regionData['sec'] ?? '') === (string)($currentForm['sec'] ?? '')
				&& (string)($regionData['lang'] ?? '') === (string)($currentForm['lang'] ?? ''))
			{
				return $regionData;
			}
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

		$result['db_name'] = \Bitrix\Main\Application::getConnection()->getDatabase();
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

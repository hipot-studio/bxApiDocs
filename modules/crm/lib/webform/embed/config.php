<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage intranet
 * @copyright 2001-2019 Bitrix
 */

namespace Bitrix\Crm\WebForm\Embed;

use Bitrix\Crm\Feature\WebFormConsentBySubmit;
use Bitrix\Crm\WebForm\Agreement\Batch;
use Bitrix\Crm\WebForm\ReCaptcha;
use Bitrix\Crm\WebForm\YandexCaptcha;
use Bitrix\Main;
use Bitrix\Crm\WebForm;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Feature;

/**
 * Class Config
 * @package Bitrix\Crm\WebForm\Embed
 */
class Config
{
	/**
	 * Version of the form configuration contract.
	 *
	 * `crm` and its consumers (landing editor, published form bundle) are released separately, so a
	 * consumer cannot tell an old server from a new one by looking at the data alone: an empty form
	 * has no legal document to carry a new field. The version says it explicitly, and its absence
	 * means version 1 - a server that knows nothing about the modes of a legal document.
	 */
	public const SCHEMA_VERSION = 2;

	public const AGREEMENT_NOTICE_TEXT_MAX_LENGTH = 1000;

	private const PERSONAL_DATA_AGREEMENT_CODE = 'crm_def';

	/** @var WebForm\Form $form Form. */
	protected $form;

	/** @var array $fields Fields. */
	protected $fields = [];

	/** @var WebForm\Options\Fields $fieldsConfig Fields config. */
	protected $fieldsConfig;

	/** @var WebForm\Options\Deps $deps Dep config. */
	protected $deps;

	/** @var bool $editMode Edit mode with special using fields like `id`.  */
	protected $editMode = false;

	/**
	 * Create config by form ID.
	 *
	 * @param int $formId Form ID.
	 * @return static
	 */
	public static function createById($formId)
	{
		return new static(new Webform\Form($formId));
	}

	/**
	 * Effective version of the form configuration exposed to clients.
	 *
	 * Kept as a method so that a module released separately can probe it with `method_exists()`
	 * instead of guessing the abilities of `crm` by the shape of the data it returns.
	 */
	public static function getSchemaVersion(): int
	{
		return self::isAcceptBySubmitEnabled() ? self::SCHEMA_VERSION : 1;
	}

	public static function isAcceptBySubmitEnabled(): bool
	{
		return class_exists(Feature::class)
			&& class_exists(WebFormConsentBySubmit::class)
			&& Feature::isEnabled(WebFormConsentBySubmit::class);
	}

	/**
	 * Config constructor.
	 *
	 * @param WebForm\Form $form
	 */
	public function __construct(WebForm\Form $form)
	{
		$this->form = $form;
		$this->deps = new WebForm\Options\Deps($this->form);
	}

	/**
	 * Set edit mode.
	 *
	 * @param bool $mode Mode.
	 * @return $this
	 */
	public function setEditMode($mode)
	{
		$this->editMode = $mode;
		if ($this->fieldsConfig !== null)
		{
			$this->fieldsConfig->setEditMode($this->editMode);
		}
		return $this;
	}

	private function getFieldsConfig(): WebForm\Options\Fields
	{
		if ($this->fieldsConfig === null)
		{
			$this->fieldsConfig = (new WebForm\Options\Fields($this->form))->setEditMode($this->editMode);
		}

		return $this->fieldsConfig;
	}

	/**
	 * Convert config to array.
	 *
	 * @return array
	 */
	public function toArray()
	{
		$data = $this->form->get();

		$config = [
			'id' => $data['ID'],
			'sec' => $data['SECURITY_CODE'],
			'lang' => $this->form->getLanguageId(),
			'address' => Main\Web\WebPacker\Builder::getDefaultSiteUri(),
			'views' => $this->getViews(),
			'data' => [
				'schemaVersion' => self::getSchemaVersion(),
				'acceptBySubmitEnabled' => self::isAcceptBySubmitEnabled(),
				'agreementNoticeSupported' => true,
				'agreementNoticeTextMaxLength' => self::AGREEMENT_NOTICE_TEXT_MAX_LENGTH,
				'language' => $this->form->getLanguageId(),
				'editMode' => $this->editMode,
				'design' => $this->getDesign(),
				'title' => $data['CAPTION'],
				'desc' => $this->getDescription(),
				'buttonCaption' => $data['BUTTON_CAPTION'],
				'useSign' => $data['COPYRIGHT_REMOVED'] !== 'Y' || !WebForm\Form::canRemoveCopyright(),
				'date' => [
					'dateFormat' => Main\Context::getCurrent()->getCulture()->getDateFormat(),
					'dateTimeFormat' => Main\Context::getCurrent()->getCulture()->getDateTimeFormat(),
					'sundayFirstly' => Main\Context::getCurrent()->getCulture()->getWeekStart() == 0,
				],
				'currency' => $this->getCurrency(),
				'fields' => $this->getFields(),
				'agreements' => $this->getAgreements(),
				'dependencies' => $this->getDependencies(),
				'captcha' => [
					'service' => Option::get('crm', 'crm_form_captcha_service', 'google'),
					'recaptcha' => [
						'use' => $this->form->isUsedCaptcha(),
					],
					'yandexCaptcha' => [
						'use' => $this->form->isUsedCaptcha(),
					],
				],
			]
		];

		// The public config is shared by every visitor of the form and is cached as a whole,
		// so a right of the current user belongs to the editor answer only.
		if ($this->editMode)
		{
			$config['data']['canViewAcceptedList'] = WebForm\Agreement\Access::canViewAcceptedList();
		}

		return $config;
	}

	public function setDataFromArray(array $data)
	{
		$this->getFieldsConfig()->setData($data['fields'] ?? []);
		$this->deps->setData($data['dependencies'] ?? []);
		$agreements = $this->filterAgreements(
			$data['agreements'] ?? [],
			($data['acceptBySubmitEnabled'] ?? null) === false,
		);
		$notices = [];
		foreach ($agreements as $agreement)
		{
			$notices[$agreement['id']] = [
				'ENABLED' => $agreement['agreementNoticeEnabled'] ? 'Y' : 'N',
				'TEXT' => $agreement['agreementNoticeText'],
			];
		}

		$parameters = [
			'CAPTION' => $data['title'],
			'DESCRIPTION' => $data['desc'],
			'BUTTON_CAPTION' => $data['buttonCaption'],
			'USE_CAPTCHA' => (($data['captcha']['service'] === 'google' && $data['captcha']['recaptcha']['use'])
				|| ($data['captcha']['service'] === 'yandex' && $data['captcha']['yandexCaptcha']['use'])
			) ? 'Y' : 'N',

			//'FIELDS' => [],
			//'DEPENDENCIES' => [],

			'AGREEMENTS' => array_map(
				function ($agreement)
				{
					return [
						'AGREEMENT_ID' => $agreement['id'],
						'CHECKED' => $agreement['checked'] ? 'Y' : 'N',
						'REQUIRED' => $agreement['required'] ? 'Y' : 'N',
						'ACCEPT_BY_SUBMIT' => $agreement['acceptBySubmit'] ? 'Y' : 'N',
					];
				},
				$agreements,
			),
			'FORM_SETTINGS' => ['AGREEMENT_NOTICES' => $notices],
			'COPYRIGHT_REMOVED' => (!$data['useSign'] && WebForm\Form::canRemoveCopyright()) ? 'Y' : 'N',
			'LANGUAGE_ID' => $data['language'],
		];
		$this->form->merge($parameters);
		if (!empty($data['design']) && is_array($data['design']))
		{
			$this->form->setDesignOptions($data['design']);
		}
		return $this;
	}

	/**
	 * Links a document new to this form. `null` means "the caller said nothing about this mode":
	 * the default of a freshly linked document applies, and the caller is not required to know
	 * every mode this build of `crm` supports.
	 */
	public function appendAgreement(
		$id,
		?bool $checked = null,
		?bool $required = null,
		?bool $acceptBySubmit = null,
		array $notice = [],
	)
	{
		$id = (int) $id;
		if (!$id)
		{
			return;
		}

		$agreements = $this->form->get()['AGREEMENTS'];
		$agreements[] = [
			'AGREEMENT_ID' => $id,
			'CHECKED' => ($checked ?? true) ? 'Y' : 'N',
			'REQUIRED' => ($required ?? true) ? 'Y' : 'N',
			'ACCEPT_BY_SUBMIT' => (self::isAcceptBySubmitEnabled()
				&& $acceptBySubmit
				&& self::isAcceptBySubmitAllowed($id)) ? 'Y' : 'N',
		];
		$storedNotice = $this->getStoredAgreementNotice($id);
		$notices = $this->form->get()['FORM_SETTINGS']['AGREEMENT_NOTICES'] ?? [];
		$notices[$id] = self::isAcceptBySubmitEnabled()
			? self::readAgreementNotice($notice, $storedNotice)
			: $storedNotice;
		$this->form->merge([
			'AGREEMENTS' => $agreements,
			'FORM_SETTINGS' => ['AGREEMENT_NOTICES' => $notices],
		]);
	}

	public function clearFields()
	{
		$this->getFieldsConfig()->clear();
	}

	public function appendField(array $options)
	{
		if (!empty($options['type']))
		{
			$isSupportedType = in_array(
				$options['type'],
				array_merge(
					array_keys(WebForm\Helper::getFieldNonValueTypes()),
					['product']
				)
			);
			if (!$isSupportedType)
			{
				return; //todo: need ErrorCollection
			}

			$options += [
				'name' => $options['type'] . '_' . mt_rand(1000000, 9999999),
				'label' => WebForm\Internals\FieldTable::getTypeList()[$options['type']]
			];
			switch ($options['type'])
			{
				case 'product':
					/**
					 * @var \CIBlockResult $iblockItems
					 */
					$iblockItems = \CIBlockElement::GetList(
						["SORT"=>"DESC"],
						[
							'=IBLOCK_ID' => \CCrmCatalog::EnsureDefaultExists(),
							'!==PREVIEW_PICTURE' => null,
							'!==DETAIL_PICTURE' => null,
						],
						false,
						[
							'nTopCount' => 3
						],
					);
					$items = [];

					while ($item = $iblockItems->Fetch())
					{
						$items[] = [
							'value' => $item['ID'],
							'label' => $item['NAME'],
							'price' => \CCrmProduct::getPrice($item['ID'])['PRICE'],
						];
					}
					$options += [
						'bigPic' => true,
						'multiple' => true,
						'items' => $items,
					];
					break;
				default:
					$options += [
						'type' => 'layout',
						'content' => ['type' => $options['type']],
					] + $options;
			}
		}

		$this->getFieldsConfig()->append($options);
	}

	/**
	 * Get views.
	 *
	 * @return array
	 */
	public function getViews()
	{
		$data = $this->form->get();
		return $data['FORM_SETTINGS']['VIEWS'];
	}

	/**
	 * Get design.
	 *
	 * @return array
	 */
	public function getDesign()
	{
		$design = $this->form->getDesignOptions(true);
		if (!$this->editMode)
		{
			unset($design['theme']);
		}

		foreach ($design as $key => $value)
		{
			if (is_array($value))
			{
				$value = array_filter(
					$value,
					function ($v)
					{
						return is_bool($v) ? true : mb_strlen($v) > 0;
					}
				);
				if (count($value) > 0)
				{
					continue;
				}
			}
			else
			{
				if (mb_strlen($value) > 0)
				{
					continue;
				}
			}

			unset($design[$key]);
		}

		return $design;
	}

	/**
	 * Return true if disabled.
	 *
	 * @return bool
	 */
	public function isDisabled()
	{
		return !$this->form->isActive();
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function getFields()
	{
		if (!$this->editMode && $this->isDisabled())
		{
			return $this->fields;
		}

		$this->fields = $this->getFieldsConfig()->toArray();

		return $this->fields;
	}

	/**
	 * Get dependencies.
	 *
	 * @return array
	 */
	public function getDependencies()
	{
		return $this->deps->toArray();
	}

	private static function isPersonalDataAgreement(Main\UserConsent\Agreement $agreement): bool
	{
		return $agreement->getData()['CODE'] === self::PERSONAL_DATA_AGREEMENT_CODE
			&& $agreement->isActive();
	}

	/**
	 * Get agreements.
	 *
	 * @param int[]|null $onlyAgreementIds Agreement IDs to include.
	 * @return array
	 */
	public function getAgreements(?array $onlyAgreementIds = null)
	{
		$result = [];

		if (!$this->editMode && $this->isDisabled())
		{
			return $result;
		}

		$data = $this->form->get();
		if (!$this->editMode && $data['USE_LICENCE'] !== 'Y')
		{
			return $result;
		}

		$agreements = [];
		if ($data['AGREEMENT_ID'])
		{
			$agreements[$data['AGREEMENT_ID']] = [
				'ID' => $data['AGREEMENT_ID'],
				'CHECKED' => $data['LICENCE_BUTTON_IS_CHECKED'] === 'Y',
				'REQUIRED' => true,
				'ACCEPT_BY_SUBMIT' => ($data['LICENCE_BUTTON_ACCEPT_BY_SUBMIT'] ?? 'N') === 'Y',
			];
		}

		foreach ($data['AGREEMENTS'] as $agreementRow)
		{
			$agreements[$agreementRow['AGREEMENT_ID']] = [
				'ID' => $agreementRow['AGREEMENT_ID'],
				'CHECKED' => $agreementRow['CHECKED'] === 'Y',
				'REQUIRED' => $agreementRow['REQUIRED'] === 'Y',
				'ACCEPT_BY_SUBMIT' => ($agreementRow['ACCEPT_BY_SUBMIT'] ?? 'N') === 'Y',
			];
		}
		if ($onlyAgreementIds !== null)
		{
			$agreements = array_intersect_key($agreements, array_fill_keys($onlyAgreementIds, true));
		}
		$agreements = array_values($agreements);

		if (empty($agreements))
		{
			return $result;
		}

		$replace = ['button_caption' => $data['BUTTON_CAPTION']];
		$fieldLabels = null;

		$agreementObjects = Batch::load(array_column($agreements, 'ID'), $replace);
		foreach ($agreements as $agreementData)
		{
			$agreement = $agreementObjects[(int)$agreementData['ID']] ?? null;
			if (!$agreement || !$agreement->isActive() || !$agreement->isExist())
			{
				continue;
			}

			// Custom content is literal in Main Agreement. Only its label can use form fields;
			// standard documents keep the full replacement set for native language/provider rendering.
			if ($agreement->getData()['TYPE'] === Main\UserConsent\Agreement::TYPE_STANDARD
				|| str_contains(Main\UserConsent\Text::replace($agreement->getData()['LABEL_TEXT'], $replace), '%fields%'))
			{
				$fieldLabels ??= array_column($this->getFields(), 'label');
				$agreement->setReplace($replace + ['fields' => $fieldLabels]);
			}

			$content = [
				'title' => $agreement->getTitle(),
				'url' => $agreement->getUrl(),
			];
			if ($agreement->isAgreementTextHtml())
			{
				$content['html'] = $agreement->getHtml();
			}
			else
			{
				$content['text'] = $agreement->getText(true);
			}

			$name = 'AGREEMENT_' . $agreementData['ID'];
			$label = $agreement->getLabel();
			$isPersonalDataAgreement = self::isPersonalDataAgreement($agreement);
			$acceptBySubmit = self::isAcceptBySubmitEnabled()
				&& $agreementData['ACCEPT_BY_SUBMIT'];
			$notice = $this->getStoredAgreementNotice((int)$agreementData['ID']);
			$result[] = [
				'id' => $this->editMode ? $agreementData['ID'] : $name,
				'name' => $name,
				'label' => $label,
				'revision' => hash('sha256', Main\Web\Json::encode([$label, $content])),
				'value' => 'Y',
				'required' => $agreementData['ACCEPT_BY_SUBMIT'] && !$acceptBySubmit
					? true
					: $agreementData['REQUIRED'],
				'checked' => $agreementData['ACCEPT_BY_SUBMIT'] && !$acceptBySubmit
					? false
					: $agreementData['CHECKED'],
				'acceptBySubmit' => $acceptBySubmit,
				'acceptBySubmitAllowed' => self::isAcceptBySubmitEnabled(),
				'isPersonalDataAgreement' => $isPersonalDataAgreement,
				'agreementNoticeAllowed' => self::isAcceptBySubmitEnabled()
					&& !$isPersonalDataAgreement
					&& !$agreementData['REQUIRED']
					&& !$agreementData['ACCEPT_BY_SUBMIT'],
				'agreementNoticeEnabled' => $notice['ENABLED'] === 'Y',
				'agreementNoticeText' => $notice['TEXT'],
				'content' => $content,
			];
		}

		return $result;
	}

	/**
	 * Get Currency.
	 *
	 * @return array
	 */
	public function getCurrency()
	{
		$parameters = \CCrmCurrency::GetCurrencyFormatParams($this->form->getCurrencyId());
		if(!is_array($parameters))
		{
			$result = [
				'code' => $this->form->getCurrencyId(),
				'title' => $this->form->getCurrencyId(),
				'format' => '# ' . $this->form->getCurrencyId(),
				/*
				'DEC_POINT' => '.',
				'DECIMALS' => 2,
				'THOUSANDS_SEP' => ' ',
				*/
			];
		}
		else
		{
			$result = [
				'code' => $parameters['CURRENCY'],
				'title' => $parameters['FULL_NAME'],
				'format' => $parameters['FORMAT_STRING'],
				/*
				'DEC_POINT' => $parameters['DEC_POINT'],
				'DECIMALS' => $parameters['DECIMALS'],
				'THOUSANDS_SEP' => $parameters['THOUSANDS_SEP'],
				*/
			];
		}

		return $result;
	}

	/**
	 * Get description.
	 *
	 * @return string
	 */
	public function getDescription()
	{
		return (new \CTextParser())->convertText($this->form->get()['DESCRIPTION']);
	}

	private function filterAgreements(array $list, bool $wasDisabled)
	{
		$stored = $this->getStoredAgreements();
		$result = [];
		foreach ($list as $item)
		{
			if (!is_array($item) || empty($item['id']))
			{
				continue;
			}

			$id = (int) $item['id'];
			if (!$id)
			{
				continue;
			}

			$storedModes = $stored[$id] ?? [];
			$notice = $this->getStoredAgreementNotice($id);
			if (self::isAcceptBySubmitEnabled() && !$wasDisabled)
			{
				$notice = self::readAgreementNotice($item, $notice);
			}
			$acceptBySubmit = ($wasDisabled ? null : self::readMode($item, 'acceptBySubmit'))
				?? self::storedMode($storedModes, 'ACCEPT_BY_SUBMIT', false);

			$result[] = [
				'id' => $id,
				'checked' => self::readMode($item, 'checked') ?? self::storedMode($storedModes, 'CHECKED', true),
				'required' => self::readMode($item, 'required') ?? self::storedMode($storedModes, 'REQUIRED', true),
				'acceptBySubmit' => self::isAcceptBySubmitEnabled()
					? $acceptBySubmit && self::isAcceptBySubmitAllowed($id)
					: self::storedMode($storedModes, 'ACCEPT_BY_SUBMIT', false),
				'agreementNoticeEnabled' => $notice['ENABLED'] === 'Y',
				'agreementNoticeText' => $notice['TEXT'],
			];
		}

		return $result;
	}

	private function getStoredAgreementNotice(int $agreementId): array
	{
		$notice = $this->form->get()['FORM_SETTINGS']['AGREEMENT_NOTICES'][$agreementId] ?? [];

		return [
			'ENABLED' => ($notice['ENABLED'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'TEXT' => is_string($notice['TEXT'] ?? null) ? $notice['TEXT'] : '',
		];
	}

	private static function readAgreementNotice(array $item, array $stored): array
	{
		if (isset($item['agreementNoticeEnabled']))
		{
			if (!is_bool($item['agreementNoticeEnabled']))
			{
				throw new Main\ArgumentException('Agreement notice mode must be boolean.', 'agreementNoticeEnabled');
			}

			$stored['ENABLED'] = $item['agreementNoticeEnabled'] ? 'Y' : 'N';
		}
		if (isset($item['agreementNoticeText']))
		{
			$text = $item['agreementNoticeText'];
			if (!is_string($text) || mb_strlen($text) > self::AGREEMENT_NOTICE_TEXT_MAX_LENGTH)
			{
				throw new Main\ArgumentException('Agreement notice text is invalid.', 'agreementNoticeText');
			}

			$stored['TEXT'] = $text;
		}

		return $stored;
	}

	/**
	 * Modes of the documents already linked to this form, indexed by document ID.
	 *
	 * Read before the incoming payload is applied, so it holds what is stored: `Options::merge()`
	 * converts the form options first, and that conversion carries no `AGREEMENTS` key.
	 */
	private function getStoredAgreements(): array
	{
		$result = [];
		foreach ($this->form->get()['AGREEMENTS'] ?? [] as $row)
		{
			$id = (int)($row['AGREEMENT_ID'] ?? $row['ID'] ?? 0);
			if ($id)
			{
				$result[$id] = $row;
			}
		}

		return $result;
	}

	/**
	 * A mode missing from the payload, unlike an explicit `false`, is not an instruction.
	 *
	 * `crm` and its editors are released separately, so a client that does not know a mode yet
	 * sends no key for it - and must not silently reset that mode for the documents of the form.
	 * Removing a document is still expressed by leaving the whole document out of the payload.
	 */
	private static function readMode(array $item, string $key): ?bool
	{
		if (!array_key_exists($key, $item) || $item[$key] === null)
		{
			return null;
		}

		return (bool)$item[$key];
	}

	private static function storedMode(array $storedModes, string $key, bool $default): bool
	{
		return array_key_exists($key, $storedModes) ? $storedModes[$key] === 'Y' : $default;
	}

	private static function isAcceptBySubmitAllowed(int $agreementId): bool
	{
		$agreement = new Main\UserConsent\Agreement($agreementId);

		return $agreement->isExist() && $agreement->isActive();
	}

	private static function filterFields()
	{

	}

	private static function filterDependencies(array $deps, array $fields)
	{
		$dict = WebForm\Options\Dictionary::instance()->getDeps();
		$actionTypes = array_column($dict['action']['types'], 'id');
		$conditionEvents = array_column($dict['condition']['events'], 'id');
		$conditionOperations = array_column($dict['condition']['operations'], 'id');
		$conditionOperations[] = '<>';

		$result = [];
		foreach ($deps as $dep)
		{
			if (!is_array($dep))
			{
				continue;
			}

			$condition = $dep['condition'] ?? null;
			$action = $dep['action'] ?? null;
			if (!$condition || !$action)
			{
				continue;
			}

			// TODO: $condition['target'] check existed in fields
			$condition['event'] = $condition['event'] ?? null;
			if (!$condition['event'] || !in_array($condition['event'], $conditionEvents))
			{
				$condition['event'] = $conditionEvents[0];
			}
			$condition['operation'] = $condition['operation'] ?? null;
			if (!$condition['operation'] || !in_array($condition['operation'], $conditionOperations))
			{
				$condition['operation'] = $conditionOperations[0];
			}
			$condition['value'] = $condition['value'] ?? null;

			// TODO: $action['target'] check existed in fields
			$action['type'] = $action['type'] ?? null;
			if (!$action['type'] || !in_array($action['type'], $actionTypes))
			{
				$action['type'] = $actionTypes[0];
			}
			$action['value'] = $action['value'] ?? null;

			$result[] = [
				'condition' => $condition,
				'action' => $action,
			];
		}

		return $result;
	}
}

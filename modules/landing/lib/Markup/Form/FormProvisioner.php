<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\PhoneVerify;
use Bitrix\Crm\Integration\UserConsent;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\WebForm;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\Package;
use Bitrix\Main\Loader;

/**
 * Gives every form token placed by a marker of a markup package a CRM form (ADR D2-D4).
 *
 * A token bound in the package itself (a retry of the step) or in the previous version of the block keeps
 * its form while the form exists: only the design follows the declaration, the fields are changed by the
 * tools. Otherwise a form is created from the feedback preset of the CRM, with the declared fields mapped
 * to the scheme of the portal. Nothing is ever deleted: a token without a marker just gets no binding.
 *
 * Unsupported input and refusals of the CRM never fail the generation: the token stays unbound and the
 * agent gets a warning {code, token, details}.
 */
class FormProvisioner
{
	public const WARNING_UNDECLARED = 'MARKUP_FORM_UNDECLARED';
	public const WARNING_FIELDS_IGNORED = 'MARKUP_FORM_FIELDS_IGNORED';
	public const WARNING_UNPLACED = 'MARKUP_FORM_UNPLACED';
	public const WARNING_UNAVAILABLE = 'MARKUP_FORM_UNAVAILABLE';
	public const WARNING_NO_RIGHTS = 'MARKUP_FORM_NO_RIGHTS';
	public const WARNING_NOT_CREATED = 'MARKUP_FORM_NOT_CREATED';

	public const REASON_FORM_REUSED = 'form_reused';
	public const REASON_LIMIT = 'limit';
	public const REASON_TARIFF = 'tariff';
	public const REASON_PHONE_NOT_VERIFIED = 'phone_not_verified';
	public const REASON_ACTIVE_FORMS_LIMIT = 'active_forms_limit';

	/** Marks the forms created for markup pages: they must not pass for the feedback preset itself. */
	public const FORM_XML_ID = 'landing_markup';

	private const PRESET_XML_ID = 'crm_preset_fb';
	private const NAME_SEPARATOR = ' — ';
	private const NAME_LENGTH = 255;
	private const DARK_BACKGROUND = '#0f172a';

	/** The plan feature the CRM requires to change any web form. */
	private const FEATURE_FORM_WRITE = 'crm_webform_edit';

	/** Licenses on which the CRM asks for a confirmed phone before a form is saved (Controller\Form). */
	private const PHONE_VERIFIED_LICENSES = ['project', 'demo'];

	private FormMarkupScanner $scanner;
	private FormFieldMapper $fieldMapper;
	private CrmFormColorNormalizer $colorNormalizer;

	private ?bool $crmAvailable = null;
	private ?bool $writeAllowed = null;
	private ?string $planRestriction = null;
	private bool $planChecked = false;

	public function __construct(
		?FormMarkupScanner $scanner = null,
		?FormFieldMapper $fieldMapper = null,
		?CrmFormColorNormalizer $colorNormalizer = null,
	)
	{
		$this->scanner = $scanner ?? new FormMarkupScanner();
		$this->fieldMapper = $fieldMapper ?? new FormFieldMapper();
		$this->colorNormalizer = $colorNormalizer ?? new CrmFormColorNormalizer();
	}

	/**
	 * @param Manifest|null $previous The version the package replaces, null for a new block.
	 * @return array{
	 *     bindings: array<string, array{formId: int, design: array}>,
	 *     warnings: list<array{code: string, token: string, details: array}>,
	 * }
	 */
	public function provision(Package $package, ?Manifest $previous, int $authorId, string $pageTitle): array
	{
		$this->crmAvailable = null;
		$this->writeAllowed = null;
		$this->planRestriction = null;
		$this->planChecked = false;

		$placedTokens = $this->findPlacedTokens($package);
		[$declarations, $currentBindings] = $this->readDeclaration($package);

		$bindings = [];
		$warnings = [];
		// a token of digits is an int key of the array
		foreach (array_map('strval', array_keys($declarations)) as $token)
		{
			if (!isset($placedTokens[$token]))
			{
				$warnings[] = self::warning(self::WARNING_UNPLACED, $token);
			}
		}

		$freeSlots = FormDeclaration::LIMIT_FORMS - count($declarations);
		foreach (array_map('strval', array_keys($placedTokens)) as $token)
		{
			$declaration = $declarations[$token] ?? null;
			if ($declaration === null)
			{
				$warnings[] = self::warning(self::WARNING_UNDECLARED, $token);
				if ($freeSlots <= 0)
				{
					$warnings[] = self::warning(self::WARNING_NOT_CREATED, $token, ['reason' => self::REASON_LIMIT]);

					continue;
				}
				$declaration = [
					'token' => $token,
					'fields' => [],
					'ignoredKinds' => [],
				];
			}

			$binding = $this->keepCurrentBinding($currentBindings[$token] ?? null)
				?? $this->provisionToken(
					$declaration,
					$previous?->getFormBinding($token),
					$authorId,
					$pageTitle,
					$warnings,
				)
			;
			if ($binding !== null)
			{
				$bindings[$token] = $binding;
				if (!isset($declarations[$token]))
				{
					$freeSlots--;
				}
			}
		}

		return [
			'bindings' => $bindings,
			'warnings' => $warnings,
		];
	}

	/**
	 * The binding the package got on an earlier run of the step: its form and design are already made
	 * from this very declaration.
	 * @return array{formId: int, design: array}|null
	 */
	private function keepCurrentBinding(?array $binding): ?array
	{
		$formId = (int)($binding['formId'] ?? 0);
		if ($formId <= 0 || !$this->checkCrmAvailable() || !$this->isFormAlive($formId))
		{
			return null;
		}

		return [
			'formId' => $formId,
			'design' => (array)($binding['design'] ?? []),
		];
	}

	/**
	 * @param array|null $knownBinding The binding of the token in the previous version.
	 * @param list<array> $warnings
	 * @return array{formId: int, design: array}|null
	 */
	private function provisionToken(
		array $declaration,
		?array $knownBinding,
		int $authorId,
		string $pageTitle,
		array &$warnings,
	): ?array
	{
		$token = $declaration['token'];
		$knownFormId = (int)($knownBinding['formId'] ?? 0);
		if ($knownFormId > 0 && $this->checkCrmAvailable() && $this->isFormAlive($knownFormId))
		{
			$declaredKinds = array_column($declaration['fields'], 'kind');
			$ignoredKinds = array_values(array_unique(array_merge($declaredKinds, $declaration['ignoredKinds'])));
			if ($ignoredKinds)
			{
				$warnings[] = self::warning(self::WARNING_FIELDS_IGNORED, $token, [
					'kinds' => $ignoredKinds,
					'reason' => self::REASON_FORM_REUSED,
				]);
			}

			return [
				'formId' => $knownFormId,
				'design' => isset($declaration['design'])
					? $this->buildDesign($declaration['design'])
					: (array)($knownBinding['design'] ?? []),
			];
		}

		if (!$this->checkCrmAvailable())
		{
			$warnings[] = self::warning(self::WARNING_UNAVAILABLE, $token);

			return null;
		}
		$this->writeAllowed ??= $authorId > 0 && $this->canWriteForms($authorId);
		if (!$this->writeAllowed)
		{
			$warnings[] = self::warning(self::WARNING_NO_RIGHTS, $token);

			return null;
		}
		// a form made earlier in this run counts against the limit of working forms, so it is asked every time
		$restriction =
			$this->checkPlanRestriction()
			?? ($this->canActivateForm() ? null : self::REASON_ACTIVE_FORMS_LIMIT)
		;
		if ($restriction !== null)
		{
			$warnings[] = self::warning(self::WARNING_NOT_CREATED, $token, ['reason' => $restriction]);

			return null;
		}

		$entityScheme = $this->getEntityScheme();
		$fields =
			$declaration['fields']
				? $this->fieldMapper->map($declaration['fields'], $entityScheme)
				: null
		;
		$ignoredKinds = array_values(array_unique(array_merge(
			$declaration['ignoredKinds'],
			$declaration['fields'] ? $this->fieldMapper->getSkippedKinds($declaration['fields'], $entityScheme) : [],
		)));
		if ($ignoredKinds)
		{
			$warnings[] = self::warning(self::WARNING_FIELDS_IGNORED, $token, ['kinds' => $ignoredKinds]);
		}

		$formId = $this->createForm(
			$this->buildFormName($pageTitle, (string)($declaration['name'] ?? $token)),
			$fields ?: null,
			$entityScheme,
			$authorId,
		);
		if (!$formId)
		{
			$warnings[] = self::warning(self::WARNING_NOT_CREATED, $token);

			return null;
		}

		return [
			'formId' => $formId,
			'design' => $this->buildDesign($declaration['design'] ?? []),
		];
	}

	/**
	 * Tokens of the markers of the entry document, in the order of their first place.
	 * @return array<string, true>
	 */
	private function findPlacedTokens(Package $package): array
	{
		$entry = $package->getEntry();
		if (!$entry)
		{
			return [];
		}

		$tokens = [];
		foreach ($this->scanner->findMarkers((string)$entry['content']) as $marker)
		{
			$tokens[$marker['token']] = true;
		}

		return $tokens;
	}

	/**
	 * Normalized elements of the declaration by token and the bindings the package already carries.
	 * The validator has refused a broken declaration before, so a broken element here is just skipped.
	 * @return array{0: array<string, array>, 1: array<string, array>}
	 */
	private function readDeclaration(Package $package): array
	{
		$declarations = [];
		$bindings = [];
		foreach ($package->getFormDeclaration() as $raw)
		{
			$declaration = FormDeclaration::normalize($raw);
			if ($declaration === null || isset($declarations[$declaration['token']]))
			{
				continue;
			}
			$declarations[$declaration['token']] = $declaration;
			if (is_array($raw['binding'] ?? null))
			{
				$bindings[$declaration['token']] = $raw['binding'];
			}
		}

		return [$declarations, $bindings];
	}

	/**
	 * The design of the CRM form: the declared colors brought to readable contrast, the font,
	 * the style and the darkness. No declared design gives the light preset.
	 */
	private function buildDesign(array $declared): array
	{
		$colors = (array)($declared['colors'] ?? []);
		$theme = $declared['theme'] ?? null;
		$background = $colors['background'] ?? ($theme === 'dark' ? self::DARK_BACKGROUND : null);
		$context = $background !== null ? ['palette' => 'bg=' . $background] : [];

		$normalized = $this->colorNormalizer->normalize($colors, $context);
		$design = [
			'color' => $normalized,
			'dark' => $theme !== null ? $theme === 'dark' : $this->isDarkBackground($normalized['background']),
		];
		if (isset($declared['style']))
		{
			$design['style'] = $declared['style'];
		}
		if (isset($declared['font']))
		{
			$design['font'] = $declared['font'];
		}

		return $design;
	}

	private function isDarkBackground(string $color): bool
	{
		return $this->colorNormalizer->contrastRatio($color, '#ffffff')
			> $this->colorNormalizer->contrastRatio($color, '#000000');
	}

	private function buildFormName(string $pageTitle, string $formName): string
	{
		$pageTitle = trim($pageTitle);
		$name = $pageTitle !== '' ? $pageTitle . self::NAME_SEPARATOR . $formName : $formName;

		return mb_substr($name, 0, self::NAME_LENGTH);
	}

	private function checkCrmAvailable(): bool
	{
		return $this->crmAvailable ??= $this->isCrmAvailable();
	}

	private function checkPlanRestriction(): ?string
	{
		if (!$this->planChecked)
		{
			$this->planRestriction = $this->findPlanRestriction();
			$this->planChecked = true;
		}

		return $this->planRestriction;
	}

	private static function warning(string $code, string $token, array $details = []): array
	{
		return [
			'code' => $code,
			'token' => $token,
			'details' => $details,
		];
	}

	/**
	 * A box with the CRM of another portal (the connector) has the forms, but cannot create them.
	 */
	protected function isCrmAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	protected function canWriteForms(int $authorId): bool
	{
		return WebForm\Manager::checkWritePermission(\CCrmPerms::GetUserPermissions($authorId));
	}

	/**
	 * Why the plan of the portal refuses a new form, as the CRM editor refuses to save one: the plan has no
	 * forms editing, or it asks for a confirmed phone, confirmed for a new form under the id zero.
	 * A box has no plan to be restricted by.
	 * @return string|null One of the REASON_ constants, null when nothing is refused.
	 */
	protected function findPlanRestriction(): ?string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		if (!Feature::isFeatureEnabled(self::FEATURE_FORM_WRITE))
		{
			return self::REASON_TARIFF;
		}

		if (
			in_array(\CBitrix24::getLicenseType(), self::PHONE_VERIFIED_LICENSES, true)
			&& !(new PhoneVerify(WebForm\Form::PHONE_VERIFY_ENTITY, '0'))->isVerified()
		)
		{
			return self::REASON_PHONE_NOT_VERIFIED;
		}

		return null;
	}

	/**
	 * The created form is active: the portal must have room for one more working form.
	 */
	protected function canActivateForm(): bool
	{
		return (bool)WebForm\Form::canActivateForm();
	}

	protected function isFormAlive(int $formId): bool
	{
		return (bool)WebForm\Internals\FormTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $formId],
			'limit' => 1,
		])->fetch();
	}

	protected function getEntityScheme(): string
	{
		return LeadSettings::getCurrent()?->isEnabled()
			? (string)WebForm\Entity::ENUM_ENTITY_SCHEME_LEAD
			: (string)WebForm\Entity::ENUM_ENTITY_SCHEME_DEAL
		;
	}

	/**
	 * Creates an active form from the feedback preset, as Subtype\Form::createForm does, with the author
	 * as the responsible. The own button of the click mode is off: the element of the page opens the form.
	 * @param list<array>|null $fields Fields of the form; null keeps the fields of the preset.
	 * @return int|null Id of the form, null when the CRM refused to save it.
	 */
	protected function createForm(string $name, ?array $fields, string $entityScheme, int $authorId): ?int
	{
		$form = new WebForm\Form();

		$data = WebForm\Preset::getById(self::PRESET_XML_ID) ?? [];
		$data['XML_ID'] = self::FORM_XML_ID;
		$data['NAME'] = $name;
		$data['ACTIVE'] = 'Y';
		$data['IS_SYSTEM'] = 'N';
		$data['IS_CALLBACK_FORM'] = 'N';
		$data['BUTTON_CAPTION'] = $form->getButtonCaption();
		$data['ENTITY_SCHEME'] = $entityScheme;
		$data['ACTIVE_CHANGE_BY'] = $authorId;
		$data['ASSIGNED_BY_ID'] = $authorId;
		if ($fields !== null)
		{
			$data['FIELDS'] = $fields;
		}

		$agreementId = UserConsent::getDefaultAgreementId();
		$data['USE_LICENCE'] = $agreementId ? 'Y' : 'N';
		if ($agreementId)
		{
			$data['LICENCE_BUTTON_IS_CHECKED'] = 'Y';
			$data['AGREEMENT_ID'] = $agreementId;
		}

		$data['FORM_SETTINGS'] = (array)($data['FORM_SETTINGS'] ?? []);
		$data['FORM_SETTINGS']['VIEWS'] = [
			'click' => [
				'type' => 'popup',
				'position' => 'center',
				'vertical' => 'bottom',
				'button' => ['use' => '0'],
			],
			'auto' => [
				'type' => 'popup',
				'position' => 'center',
				'vertical' => 'bottom',
				'delay' => 5,
			],
		];

		$form->merge($data);
		$form->save();

		return !$form->hasErrors() && $form->getId() ? (int)$form->getId() : null;
	}
}

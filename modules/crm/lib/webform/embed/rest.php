<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage intranet
 * @copyright 2001-2019 Bitrix
 */

namespace Bitrix\Crm\WebForm\Embed;

use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Rest\RestException;
use Bitrix\Crm\WebForm;
use Bitrix\Crm\V2\Public\Command\LegalRegistry\FormSubmissionCommand;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\RegistryResult;
use Bitrix\Crm\V2\Public\Provider\LegalRegistry\PresentationProvider;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FeatureGate;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormSubmissionService;
use Bitrix\Crm\WebForm\Telemetry\Recorder;
use Bitrix\Crm\WebForm\Telemetry\RecorderInterface;
use Bitrix\Crm\WebForm\Telemetry\SourceContextSigner;
use Bitrix\Crm\WebForm\Telemetry\SubmissionClassifier;
use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\SiteButton\Guest;
use Throwable;

/**
 * Class Rest
 * @package Bitrix\Crm\WebForm\Embed
 */
class Rest
{
	/**
	 * Register bindings.
	 *
	 * @param array $bindings Rest bindings.
	 * @return void
	 */
	public static function register(array &$bindings)
	{
		$bindings['crm.site.form.fill'] = [__CLASS__, 'fillForm'];
		$bindings['crm.site.form.user.get'] = [__CLASS__, 'getUser'];
	}

	/**
	 * Fill form.
	 *
	 * @param array $query Query parameters.
	 * @param RecorderInterface|null $telemetryRecorder Telemetry recorder.
	 * @return array
	 * @throws RestException
	 */
	public static function fillForm($query, ?RecorderInterface $telemetryRecorder = null)
	{
		$formId = empty($query['id']) ? null : (int) $query['id'];
		$securityCode = empty($query['sec']) ? null : $query['sec'];
		$values = (isset($query['values']) && $query['values'])
			? $query['values']
			: null;
		$properties = (isset($query['properties']) && $query['properties'])
			? $query['properties']
			: null;
		$trace = empty($query['trace']) ? null : $query['trace'];
		$consents = $query['consents'] ?? '';
		$explicitConsents = $query['explicitConsents'] ?? '';
		$displayedSubmitAgreements = $query['displayedSubmitAgreements'] ?? '';
		$recaptchaResponse = empty($query['recaptcha']) ? null : $query['recaptcha'];
		$yandexSmartCaptchaResponse = empty($query['yandexSmartCaptcha']) ? null : $query['yandexSmartCaptcha'];
		$signString = empty($query['security_sign']) ? null : $query['security_sign'];
		//$entities = empty($query['entities']) ? null : $query['entities'];

		$form = null;
		$telemetryContext = null;
		$isValidationFailure = false;
		$legalError = null;
		$normalizedRequest = null;

		try
		{
			if (array_key_exists('legalDocuments', $query))
			{
				try
				{
					$legal = self::decodeLegalPayload($query['legalDocuments']);
					$normalizedRequest = self::normalizeLegalRequest($query);
					$normalizedRequest['presentationTokenHash'] = hash('sha256', $legal['presentationToken']);
					$hash = (new FormSubmissionService())->hashNormalizedRequest($normalizedRequest);
				}
				catch (\Throwable)
				{
					$legalError = new Main\Error('Invalid legal document submission.', 'INVALID_PAYLOAD');
					self::printErrors([$legalError->getMessage()]);
				}
				$replay = (new FormSubmissionCommand(
					(int)$formId, $legal['presentationToken'], $legal['attemptKey'], $legal['answers'], $hash,
				))->replay();
				if ($replay !== null)
				{
					if (!$replay->isSuccess())
					{
						$legalError = $replay->getErrors()[0];
						self::printErrors($replay->getErrorMessages());
					}
					$result = WebForm\Fill::findLegalResult((int)$formId, $replay, $hash);
					if ($result !== null)
					{
						return $result->getLegalReplayResponse();
					}
					if ($replay->getState() !== 'pending'
						|| !WebForm\Internals\FormTable::getById((int)$formId)->fetch())
					{
						$legalError = new Main\Error('Legal document submission is pending.', 'INVALID_STATE');
						self::printErrors([$legalError->getMessage()]);
					}
				}
			}

			if (!WebForm\Manager::isEmbeddingAvailable())
			{
				self::printErrors(["Form embedding feature disabled."]);
			}

			if (!$formId)
			{
				self::printErrors(["Parameter `id` required."]);
			}
			if (!$securityCode)
			{
				self::printErrors(["Parameter `sec` required."]);
			}

			$form = new WebForm\Form($formId, null, $telemetryRecorder);
			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$telemetryContext = self::prepareTelemetryContext($query, $formId);

			$consents = $consents ? Main\Web\Json::decode($consents) : [];
			$consents = is_array($consents) ? $consents : [];
			$explicitConsents = $explicitConsents ? Main\Web\Json::decode($explicitConsents) : [];
			$explicitConsents = is_array($explicitConsents) ? $explicitConsents : [];
			$displayedSubmitAgreements = $displayedSubmitAgreements
				? Main\Web\Json::decode($displayedSubmitAgreements)
				: [];
			$displayedSubmitAgreements = is_array($displayedSubmitAgreements) ? $displayedSubmitAgreements : [];

			if ($form->isUsedCaptcha())
			{
				if (Option::get('crm', 'crm_form_captcha_service') === 'yandex')
				{
					$yandexSecret = WebForm\YandexCaptcha::getSecret() ?: WebForm\YandexCaptcha::getDefaultSecret();
					$yandexKey = WebForm\YandexCaptcha::getKey() ?: WebForm\YandexCaptcha::getDefaultKey();

					if ($yandexSecret && $yandexKey)
					{
						if (!$recaptchaResponse)
						{
							self::printErrors(["Parameter `yandexSmartCaptcha` is invalid."]);
						}

						$yandexCaptcha = new WebForm\YandexCaptcha($yandexSecret);

						if (!$yandexCaptcha->verify($yandexSmartCaptchaResponse))
						{
							self::printErrors([$yandexCaptcha->getError()]);
						}
					}
				}
				else
				{
					$recaptchaSecret = WebForm\ReCaptcha::getSecret(2) ?: WebForm\ReCaptcha::getDefaultSecret(2);
					$recaptchaKey = WebForm\ReCaptcha::getKey(2) ?: WebForm\ReCaptcha::getDefaultKey(2);

					if ($recaptchaSecret && $recaptchaKey)
					{
						if (!$recaptchaResponse)
						{
							self::printErrors(["Parameter `recaptcha` is invalid."]);
						}

						$recaptcha = new WebForm\ReCaptcha($recaptchaSecret);

						if (!$recaptcha->verify($recaptchaResponse))
						{
							self::printErrors([$recaptcha->getError()]);
						}
					}
				}
			}

			if ($telemetryContext !== null)
			{
				self::startTelemetry($telemetryContext, $formId, $telemetryRecorder);
			}

			$fill = $form->fill();

			///////////////////
			$values = $values ? Main\Web\Json::decode($values) : [];

			$properties = $properties ? Main\Web\Json::decode($properties) : [];

			$fill
				->setTrace($trace)
				->setValues($values)
				->setConsents($consents)
				->setExplicitConsents($explicitConsents)
				->setDisplayedSubmitAgreements($displayedSubmitAgreements)
				->setProperties($properties)
			;
			if ($telemetryContext !== null)
			{
				$fill->setTelemetryContext($telemetryContext);
			}

			if (array_key_exists('legalDocuments', $query))
			{
				$signString = $query['security_sign'] ?? null;
			}
			if ($signString !== null && $signString !== '')
			{
				$sign = new Sign();
				if ($sign->unpack($signString))
				{
					$fill->setEntities($sign->getEntities());
				}
				elseif (array_key_exists('legalDocuments', $query))
				{
					$legalError = new Main\Error('Invalid recipient signature.', 'INVALID_PAYLOAD');
					self::printErrors([$legalError->getMessage()]);
				}
			}

			$agreementsResult = $fill->resolveAgreements();
			if (!$agreementsResult->isSuccess())
			{
				$isValidationFailure = true;
				if ($telemetryContext !== null)
				{
					self::markTelemetryError($telemetryContext['attemptId'], $telemetryRecorder, 'validation');
				}
				self::printErrors($agreementsResult->getErrorMessages());
			}

			$legacyGuard = null;
			try
			{
				if (!array_key_exists('legalDocuments', $query))
				{
					try
					{
						$legacyGuard = \Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormMutationGuard::acquire(
							(int)$formId,
						);
					}
					catch (\RuntimeException $error)
					{
						if ($error->getMessage() !== 'REVISION_CONFLICT') { throw $error; }
						$legalError = new Main\Error('CRM form is busy.', 'INVALID_STATE');
						self::printErrors([$legalError->getMessage()]);
					}
				}
				$normalizedRequest ??= [];
				$legalSubmission = self::prepareLegalSubmission(
					$form, $query['legalDocuments'] ?? null, $normalizedRequest,
				);
				if ($legalSubmission !== null)
				{
					if (!$legalSubmission->isSuccess())
					{
						$isValidationFailure = true;
						$legalError = $legalSubmission->getErrors()[0];
						if ($telemetryContext !== null)
						{
							self::markTelemetryError($telemetryContext['attemptId'], $telemetryRecorder, 'validation');
						}
						self::printErrors($legalSubmission->getErrorMessages());
					}
					$payload = Main\Web\Json::decode($query['legalDocuments']);
					$normalizedRequest['presentationTokenHash'] = hash('sha256', $payload['presentationToken']);
					$normalizedRequestHash = (new FormSubmissionService())->hashNormalizedRequest($normalizedRequest);
					$fill->setLegalSubmission($legalSubmission, $normalizedRequestHash);
				}

				$legacyGuard?->release();
				$legacyGuard = null;
				$result = $fill->save();
			}
			finally { $legacyGuard?->release(); }

			if (!$result->getId())
			{
				$isValidationFailure = true;
				self::printErrors($result->getErrors());
			}

			if ($result->isLegalReplay())
			{
				return $result->getLegalReplayResponse();
			}

			$gid = $result->getResultEntity()->getTrace()->getGid();
			if (!$gid)
			{
				$gid = Guest::register([
					'ENTITIES' => array_map(
						function (array $item)
						{
							return [
								'ENTITY_TYPE_ID' => \CCrmOwnerType::resolveID($item['ENTITY_TYPE']),
								'ENTITY_ID' => $item['ENTITY_ID'],
							];
						},
						$result->getResultEntity()->getResultEntities()
					)
				]);
			}

			return [
				'resultId' => $result->getId(),
				'pay' => $form->isPayable(),
				'message' => $form->getSuccessText(),
				'gid' => $gid,
				'redirect' => [
					'url' => $result->getUrl(),
					'delay' => $form->getRedirectDelay(),
				],
				'refill' => [
					'active' => $form->getRefill()['ACTIVE'] === 'Y',
					'caption' => $form->getRefill()['CAPTION'],
				]
			];
		}
		catch (Throwable $exception)
		{
			if (array_key_exists('legalDocuments', $query) && $legalError === null)
			{
				$code = $exception->getMessage();
				if (!in_array($code, ['INVALID_PAYLOAD', 'IDEMPOTENCY_CONFLICT', 'INVALID_STATE',
					'STALE_PRESENTATION', 'POLICY_UNAVAILABLE', 'FEATURE_UNAVAILABLE'], true))
				{
					$code = 'STORAGE_ERROR';
				}
				$legalError = new Main\Error('Legal documents are unavailable.', $code);
			}
			if ($telemetryContext !== null && !$isValidationFailure)
			{
				self::markTelemetryError($telemetryContext['attemptId'], $telemetryRecorder);
			}

			$response = [
				'resultId' => null,
				'pay' => false,
				'message' => $legalError !== null
					? $legalError->getMessage()
					: ($form ? $form->getFailureText() : $exception->getMessage()),
				'gid' => null,
				'redirect' => [],
				'refill' => []
			];
			if ($legalError !== null)
			{
				$response['errors'] = self::legalErrors([$legalError]);
			}

			return $response;
		}
	}

	/** Returns a fresh presentation without using the shared form configuration cache. */
	public static function getLegalPresentation(array $query): array
	{
		$formId = $query['id'] ?? null;
		$code = $query['sec'] ?? null;
		if ((!is_int($formId) && (!is_string($formId) || preg_match('/^[1-9][0-9]*$/D', $formId) !== 1))
			|| (int)$formId <= 0 || (int)$formId > 2147483647 || !is_string($code) || $code === '')
		{
			return ['resultId' => null, 'errors' => self::legalErrors([new Main\Error('', 'INVALID_PAYLOAD')])];
		}
		if (!WebForm\Manager::isEmbeddingAvailable())
		{
			return ['resultId' => null, 'errors' => self::legalErrors([new Main\Error('', 'FEATURE_UNAVAILABLE')])];
		}
		$result = (new PresentationProvider())->issueForPublicForm((int)$formId, [
			'formId' => (int)$formId,
			'securityCode' => $code,
		]);
		if (!$result->isSuccess())
		{
			return ['resultId' => null, 'errors' => self::legalErrors($result->getErrors())];
		}

		return $result->getPresentation()->toArray();
	}

	/** Prepares legal documents before the form may create or update CRM data. */
	public static function prepareLegalSubmission(
		WebForm\Form $form,
		mixed $payload,
		array $normalizedRequest,
	): ?RegistryResult
	{
		$configured = (new Config($form))->hasLegalDocuments($payload === null);
		if (!$configured && $payload === null)
		{
			return null;
		}
		try
		{
			if (!$configured)
			{
				throw new \InvalidArgumentException();
			}
			$legal = self::decodeLegalPayload($payload);
			$availability = FeatureGate::requireEnabled();
			if (!$availability->isSuccess())
			{
				return $availability;
			}
			$normalizedRequest['presentationTokenHash'] = hash('sha256', $legal['presentationToken']);
			$hash = (new FormSubmissionService())->hashNormalizedRequest($normalizedRequest);

			return (new FormSubmissionCommand(
				(int)$form->getId(), $legal['presentationToken'], $legal['attemptKey'], $legal['answers'], $hash,
			))->run();
		}
		catch (\Throwable)
		{
			return (new RegistryResult())->addError(new Main\Error('', 'INVALID_PAYLOAD'));
		}
	}

	private static function decodeLegalPayload(mixed $payload): array
	{
		if (!is_string($payload) || strlen($payload) > 2097152)
		{
			throw new \InvalidArgumentException();
		}
		$legal = Main\Web\Json::decode($payload);
		if (!is_array($legal) || count($legal) !== 3
			|| array_diff(['presentationToken', 'attemptKey', 'answers'], array_keys($legal)) !== []
			|| !is_string($legal['presentationToken']) || $legal['presentationToken'] === ''
			|| !is_string($legal['attemptKey']) || trim($legal['attemptKey']) === ''
			|| strlen($legal['attemptKey']) > 255 || !is_array($legal['answers'])
			|| !array_is_list($legal['answers']) || count($legal['answers']) > 100)
		{
			throw new \InvalidArgumentException();
		}
		foreach ($legal['answers'] as $answer)
		{
			if (!is_array($answer) || count($answer) !== 3
				|| array_diff(['documentId', 'versionId', 'accepted'], array_keys($answer)) !== []
				|| !is_int($answer['documentId']) || $answer['documentId'] <= 0
				|| !is_int($answer['versionId']) || $answer['versionId'] <= 0
				|| !is_bool($answer['accepted']))
			{
				throw new \InvalidArgumentException();
			}
		}

		return $legal;
	}

	private static function normalizeLegalRequest(array $query): array
	{
		$normalized = [
			'formId' => (int)($query['id'] ?? 0),
			'securityCode' => $query['sec'] ?? null,
			'trace' => empty($query['trace']) ? null : $query['trace'],
			'language' => $query['lang'] ?? '',
			'securitySignHash' => '',
		];
		foreach (['values', 'properties', 'consents', 'explicitConsents', 'displayedSubmitAgreements'] as $key)
		{
			$value = $query[$key] ?? '';
			if (!is_string($value))
			{
				throw new \InvalidArgumentException();
			}
			$normalized[$key] = $value === '' ? [] : Main\Web\Json::decode($value);
			if (!is_array($normalized[$key]))
			{
				throw new \InvalidArgumentException();
			}
		}
		$signString = $query['security_sign'] ?? '';
		if (!is_string($signString))
		{
			throw new \InvalidArgumentException();
		}
		// Frozen replay binds the original signed bytes without rechecking their lifetime.
		$normalized['securitySignHash'] = hash('sha256', $signString);

		return $normalized;
	}

	private static function legalErrors(array $errors): array
	{
		return array_map(static fn(Main\Error $error): array => [
			'code' => $error->getCode(),
			'message' => $error->getMessage() ?: 'Legal documents are unavailable.',
		], $errors);
	}

	private static function startTelemetry(
		array $context,
		int $formId,
		?RecorderInterface $recorder,
	): void
	{
		try
		{
			$recorder ??= new Recorder();
			$recorder->start(
				$context['attemptId'],
				$formId,
				$context['source'],
				Main\Context::getCurrent()->getRequest()->getUserAgent(),
			);
		}
		catch (Throwable)
		{
		}
	}

	private static function prepareTelemetryContext(array $query, int $formId): ?array
	{
		$attemptId = $query['attemptId'] ?? null;
		if (!self::isUuidV4($attemptId))
		{
			return null;
		}

		$source = SubmissionClassifier::SOURCE_UNKNOWN;
		$signString = $query['telemetry_sign'] ?? null;
		if (is_string($signString) && $signString !== '')
		{
			$source = (new SourceContextSigner())->verify($signString, $formId)
				?? SubmissionClassifier::SOURCE_UNKNOWN
			;
		}

		return [
			'attemptId' => $attemptId,
			'source' => $source,
		];
	}

	private static function markTelemetryError(
		string $attemptId,
		?RecorderInterface $recorder,
		string $errorType = 'other',
	): void
	{
		try
		{
			($recorder ?? new Recorder())->markError($attemptId, $errorType);
		}
		catch (Throwable)
		{
		}
	}

	private static function isUuidV4(mixed $attemptId): bool
	{
		return is_string($attemptId) && preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$attemptId,
		) === 1;
	}

	/**
	 * Get form.
	 *
	 * @param array $query Query parameters.
	 * @return array
	 * @throws RestException
	 */
	public static function getForm($query)
	{
		if (($query['legalDocumentsOnly'] ?? null) === 'Y')
		{
			return self::getLegalPresentation($query);
		}

		$formId = empty($query['id']) ? null : (int) $query['id'];
		$securityCode = empty($query['sec']) ? null : $query['sec'];
		$loaderOnly = !empty($query['loaderOnly']);

		if (!WebForm\Manager::isEmbeddingAvailable())
		{
			self::printErrors(["Form embedding feature disabled."]);
		}

		if (!$formId)
		{
			self::printErrors(["Parameter `id` required."]);
		}
		if (!$securityCode)
		{
			self::printErrors(["Parameter `sec` required."]);
		}

		if (!empty($query['agreementsOnly']))
		{
			$cachePath = '/crm/webform/rest_get_form/' . $formId . '/';
			$cacheKey = 'WebFormAgreements:' . $formId
				. ':' . hash('sha256', $securityCode)
				. ':' . (Config::isAcceptBySubmitEnabled() ? '1' : '0')
			;
			$cache = Main\Application::getInstance()->getCache();
			if ($cache->initCache(15, $cacheKey, $cachePath))
			{
				return $cache->getVars();
			}

			$form = new WebForm\Form($formId);
			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$cache->startDataCache();
			$taggedCache = Main\Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($cachePath);
			$taggedCache->registerTag(WebForm\Form::getCacheTag($formId));
			$taggedCache->endTagCache();

			$response = ['agreements' => (new Config($form))->getAgreements()];
			$cache->endDataCache($response);

			return $response;
		}

		$telemetrySource = SubmissionClassifier::SOURCE_SITE;
		$telemetrySign = $query[SourceContextSigner::PARAMETER] ?? null;
		if (is_string($telemetrySign))
		{
			$telemetrySource = (new SourceContextSigner())->verify($telemetrySign, $formId)
				?? SubmissionClassifier::SOURCE_SITE
			;
		}

		$cacheKey = 'WebForm:'
			. $formId
			. ':' . ($securityCode ? substr(md5($securityCode), -6) : '')
			. ':' . ($loaderOnly ? '1' : '0')
			. ':' . $telemetrySource
			. ':' . (Config::isAcceptBySubmitEnabled() ? '1' : '0')
		;
		$cachePath = '/crm/webform/rest_get_form/'.$formId.'/';
		$ttl = 1800; // 30 min
		$cache = \Bitrix\Main\Application::getInstance()->getCache();

		if ($cache->initCache($ttl, $cacheKey, $cachePath))
		{
			$response = $cache->getVars();
		}
		else
		{
			$cache->startDataCache();
			$cacheTag = WebForm\Form::getCacheTag($formId);

			$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
			$taggedCache->startTagCache($cachePath);
			$taggedCache->registerTag($cacheTag);
			$taggedCache->endTagCache();

			$form = new WebForm\Form($formId);

			if (!$form->checkSecurityCode($securityCode))
			{
				self::printErrors(["Parameter `security_sign` is invalid."]);
			}

			if (!$form->isActive())
			{
				self::printErrors(["Form with id=`$formId` is disabled."]);
			}

			$appPack = Webpack\Form\App::instance();
			$scripts = WebForm\Script::getListContext($form->get(), [], null, $telemetrySource);

			$response = [
				'config' => $loaderOnly ? null : (new Config($form))->toArray(),
				'loader' => [
					'form' => [
						'inline' => $scripts['INLINE']['text'],
						'click' => $scripts['CLICK']['text'],
						'auto' => $scripts['AUTO']['text'],
					],
					'app' => [
						'link' => $appPack->getEmbeddedFileUrl(),
						'script' => $appPack->getEmbeddedBody(),
					],
				],
			];

			$cache->endDataCache($response);
		}

		return $response;
	}

	/**
	 * Get user.
	 *
	 * @param array $query Query parameters.
	 * @return array
	 * @throws RestException
	 */
	public static function getUser($query)
	{
		$sign = empty($query['security_sign']) ? null : $query['security_sign'];
		if (!$sign)
		{
			self::printErrors(["Parameter `security_sign` required."]);
		}

		if (!WebForm\Manager::isEmbeddingAvailable())
		{
			self::printErrors(["Form embedding feature disabled."]);
		}

		$hash = new Sign();

		if (!$hash->unpack($sign))
		{
			self::printErrors(["Parameter `security_sign` is not valid."]);
		}

		return User::getData($hash->getEntities()) + ['properties' => $hash->getProperties()];
	}

	/**
	 * Print rest errors.
	 *
	 * @param string[] $errors Errors.
	 * @param string $errorCode Error Code.
	 * @return void
	 * @throws RestException
	 */
	protected static function printErrors(array $errors,  $errorCode = RestException::ERROR_CORE)
	{
		foreach ($errors as $error)
		{
			throw new RestException(
				$error,
				$errorCode,
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}
	}
}

<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Main;
use Bitrix\Mail;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Mail\Helper\OAuth\UserData;
use Bitrix\Socialservices\OAuth\StateService;


abstract class OAuth
{

	/**
	 * @var \CSocServOAuthTransport
	 */
	protected $oauthEntity;

	protected $service, $storedUid;

	public const WEB_TYPE = 'web';
	public const MOBILE_TYPE = 'mobile';

	public const ERROR_SCENARIO_ADMIN_CONSENT = 'admin_consent';
	public const ERROR_SCENARIO_BLOCKED_BY_ADMIN = 'blocked_by_admin';
	public const ERROR_SCENARIO_RETRY = 'retry';
	public const ERROR_SCENARIO_CANCELLED = 'cancelled';
	public const ERROR_SCENARIO_GENERIC = 'generic';
	protected UserData $publicUserData;
	private $storedTokenRow = null;

	/**
	 * Returns the list of supported services
	 *
	 * @return array
	 */
	public static function getKnownServices()
	{
		static $knownServices;

		if (is_null($knownServices))
		{
			$knownServices = [
				OAuth\Google::getServiceName(),
				OAuth\LiveId::getServiceName(),
				OAuth\Yandex::getServiceName(),
				OAuth\Mailru::getServiceName(),
				OAuth\Office365::getServiceName(),
			];
		}

		return $knownServices;
	}

	/**
	 * Returns helper instance
	 *
	 * @param string $service Service name.
	 * @return \Bitrix\Mail\Helper\OAuth|false
	 */
	public static function getInstance($service = null): bool|OAuth
	{
		if (get_called_class() != get_class())
		{
			$className = get_called_class();
			$service = $className::getServiceName();
		}
		else
		{
			if (!in_array($service, self::getKnownServices()))
			{
				return false;
			}

			$className = sprintf('%s\OAuth\%s', __NAMESPACE__, $service);
		}

		if (!Main\Loader::includeModule('socialservices'))
		{
			return false;
		}

		$instance = new $className;

		$instance->service = $service;
		$instance->storedUid = sprintf('%x%x', time(), rand(0, 0xffffffff));

		if (!$instance->check())
		{
			return false;
		}

		return $instance;
	}

	/**
	 * Returns helper instance by packed metadata
	 *
	 * @param string $meta Packed metadata.
	 * @return \Bitrix\Mail\Helper\OAuth|false
	 */
	public static function getInstanceByMeta($meta)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ($instance = self::getInstance($meta['service']))
			{
				if ('oauthb' == $meta['type'])
				{
					$instance->storedUid = $meta['key'];
				}

				return $instance;
			}
		}
	}

	/**
	 * Returns packed metadata for instance
	 *
	 * @return string
	 * @throws Main\ObjectException
	 */
	public function buildMeta()
	{
		return sprintf(
			"\x00oauthb\x00%s\x00%s",
			$this->getServiceName(),
			$this->getStoredUid()
		);
	}

	/**
	 * Returns unpacked metadata
	 *
	 * @param string $meta Packed metadata.
	 * @return array
	 */
	public static function parseMeta($meta)
	{
		$regex = sprintf(
			'/^\x00(oauthb)\x00(%s)\x00([a-f0-9]+)$/',
			join(
				'|',
				array_map(
					function ($item)
					{
						return preg_quote($item, '/');
					},
					self::getKnownServices()
				)
			)
		);

		if (!preg_match($regex, $meta, $matches))
		{
			if (!preg_match('/^\x00(oauth)\x00(google|liveid)\x00(\d+)$/', $meta, $matches))
			{
				return null;
			}
		}

		return array(
			'type' => $matches[1],
			'service' => $matches[2],
			'key' => $matches[3],
		);
	}

	/**
	 * Returns token from socialservices
	 *
	 * @param string $service Service name.
	 * @param string $key User ID.
	 * @return string|null
	 */
	private static function getSocservToken($service, $key)
	{
		if (Main\Loader::includeModule('socialservices'))
		{
			switch ($service)
			{
				case 'google':
					$oauthClient = new \CSocServGoogleOAuth($key);
					$oauthClient->getEntityOAuth()->addScope('https://mail.google.com/');
					break;
				case 'liveid':
					$oauthClient = new \CSocServLiveIDOAuth($key);
					$oauthClient->getEntityOAuth()->addScope(['wl.imap', 'wl.offline_access']);
					break;
			}

			if (!empty($oauthClient))
			{
				return $oauthClient->getStorageToken() ?: false;
			}
		}

		return null;
	}

	/**
	 * Returns token by packed metadata
	 *
	 * @param string $meta Packed metadata.
	 * @param int $expireGapSeconds Gap needed for using token, to ensure when we use token it still alive
	 *
	 * @return string|null
	 */
	public static function getTokenByMeta($meta, int $expireGapSeconds = 10)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ('oauthb' == $meta['type'])
			{
				if ($oauthHelper = self::getInstance($meta['service']))
				{
					return $oauthHelper->getStoredToken($meta['key'], $expireGapSeconds) ?: false;
				}
			}
			else if ('oauth' == $meta['type'])
			{
				return self::getSocservToken($meta['service'], $meta['key']);
			}
		}
	}

	/**
	 * Returns user data by packed metadata
	 *
	 * @param string $meta Packed metadata.
	 * @param boolean $secure Strip raw data (includes tokens).
	 * @return array|null
	 */
	public static function getUserDataByMeta($meta, $secure = true)
	{
		if ($meta = static::parseMeta($meta))
		{
			if ($oauthHelper = self::getInstance($meta['service']))
			{
				if ('oauthb' == $meta['type'])
				{
					$oauthHelper->getStoredToken($meta['key']);
				}
				else if ('oauth' == $meta['type'])
				{
					if ($token = self::getSocservToken($meta['service'], $meta['key']))
					{
						$oauthHelper->getOAuthEntity()->setToken($token);
					}
				}

				return $oauthHelper->getUserData($secure);
			}
		}

		return null;
	}

	/**
	 * Returns service interface entity
	 *
	 * @return mixed
	 */
	public function getOAuthEntity()
	{
		return $this->oauthEntity;
	}

	/**
	 * Returns instance UID
	 *
	 * @return string
	 */
	public function getStoredUid()
	{
		return $this->storedUid;
	}

	/**
	 * Returns server OAuth handler URI
	 *
	 * @param boolean $final Skip Bitrix24 proxy.
	 * @return string
	 */
	public function getRedirect(bool $final = true): string
	{
		if (isModuleInstalled('bitrix24') && defined('BX24_HOST_NAME') && !$final)
		{
			return $this->getControllerUrl() . '/redirect.php';
		}
		else
		{
			$uri = new Uri(Main\Engine\UrlManager::getInstance()->getHostUrl().'/bitrix/tools/mail_oauth.php');
			return $uri->getLocator();
		}
	}

	/**
	 * Returns service OAuth endpoint URI
	 *
	 * @return string
	 */
	public function getUrl(): string
	{
		$stateFields = [
			'check_key' => \CSocServAuthManager::getUniqueKey(),
			'service' => $this->service,
			'uid' => $this->storedUid,
		];
		$stateToken = StateService::getInstance()->createState($stateFields);

		if (isModuleInstalled('bitrix24') && defined('BX24_HOST_NAME'))
		{
			$portalRedirectUri = new Uri($this->getRedirect());
			$portalRedirectUri->addParams([
				'state' => $stateToken,
			]);
			$state = (string)$portalRedirectUri;
		}
		else
		{
			$state = $stateToken;
		}

		$redirect = $this->getRedirect(false);

		if ($this->oauthEntity instanceof \CMailRu2Interface)
		{
			return $this->oauthEntity->getAuthUrl($redirect, $state, true);
		}

		return $this->oauthEntity->getAuthUrl($redirect, $state);
	}

	/**
	 * Fetches token by instance UID from DB
	 *
	 * @return array|false
	 */
	protected function fetchStoredToken(): bool|array
	{
		if (!is_null($this->storedTokenRow))
		{
			return $this->storedTokenRow;
		}

		$this->storedTokenRow = Mail\Internals\OAuthTable::getList([
			'select' => [
				'ID',
				'UID',
				'TOKEN',
				'REFRESH_TOKEN',
				'TOKEN_EXPIRES',
			],
			'filter' => [
				'=UID' => $this->storedUid,
			],
			'order' => [
				'ID' => 'DESC',
			],
		])->fetch();

		if ($this->storedTokenRow === false)
		{
			$this->storedTokenRow = null;

			return false;
		}

		return $this->storedTokenRow;
	}

	protected function setStoredToken(array $row, int $id): void
	{
		$result = Mail\Internals\OAuthTable::update(
			$id,
			$row,
		);

		if ($result->isSuccess())
		{
			// The row is merged over the one already read, so a field left out of the update
			// keeps its stored value instead of disappearing from the cached row.
			$this->storedTokenRow = array_merge((array)$this->storedTokenRow, $row, [
				'ID' => $id,
				'UID' => $this->storedUid,
			]);
		}
	}

	public function renewTokens(): bool
	{
		$storedTokenRow = $this->fetchStoredToken();

		if (empty($storedTokenRow) || empty($storedTokenRow['REFRESH_TOKEN']))
		{
			// A renewal that cannot even start leaves in the entity only a token whose lifetime is
			// not over: an expired one handed out afterwards would look alive to IMAP and SMTP,
			// while a live one is still the token of the mailbox.
			$this->oauthEntity->setToken(self::tokenStillAlive($storedTokenRow));
			// Nothing was asked of the provider here, so it named no code.
			$this->logRenewFailure((int)($storedTokenRow['ID'] ?? 0), '');

			return false;
		}

		$this->setOAuthEntityTokens($storedTokenRow);
		$this->oauthEntity->setToken(null);

		if (!$this->oauthEntity->getNewAccessToken($storedTokenRow['REFRESH_TOKEN']))
		{
			$this->oauthEntity->setToken(self::tokenStillAlive($storedTokenRow));
			$this->logRenewFailure((int)$storedTokenRow['ID'], $this->getProviderRefreshErrorCode());

			return false;
		}

		$tokenData = $this->oauthEntity->getTokenData();

		$row = [
			'TOKEN' => $tokenData['access_token'],
			'TOKEN_EXPIRES' => $tokenData['expires_in'],
		];

		if (!empty($tokenData['refresh_token']))
		{
			$row['REFRESH_TOKEN'] = $tokenData['refresh_token'];
		}

		$this->setStoredToken($row, (int)$storedTokenRow['ID']);

		return true;
	}

	private function logRenewFailure(int $oauthId, string $providerErrorCode): void
	{
		(new Main\Diag\LoggerFactory())->createById('mail.OAuth')?->error(
			// The formatter substitutes the context into the placeholders of the template only,
			// so a template without them reaches the journal carrying nothing.
			'OAuth token renewal failed. providerErrorCode={providerErrorCode}, oauthId={oauthId}',
			self::buildRenewFailureLogContext($providerErrorCode, $oauthId),
		);
	}

	/**
	 * The stored token while its lifetime is not over, null otherwise: a refused renewal hands out
	 * no expired token and takes away no live one.
	 *
	 * TOKEN_EXPIRES is a unix time, and zero there means the lifetime is unknown - not something
	 * to hand out on a refusal.
	 *
	 * @param array|bool|null $storedTokenRow Row of the token storage.
	 * @return string|null
	 */
	private static function tokenStillAlive(array|bool|null $storedTokenRow): ?string
	{
		$row = (array)$storedTokenRow;

		return !empty($row['TOKEN']) && (int)($row['TOKEN_EXPIRES'] ?? 0) > time()
			? (string)$row['TOKEN']
			: null;
	}

	/**
	 * Payload of the renewal failure record: the provider error code and the OAuth row.
	 *
	 * Neither the token nor the refresh token belongs here - a journal is read by more people
	 * than a mailbox has.
	 *
	 * @param string $providerErrorCode Provider error code, already reduced to an identifier.
	 * @param int $oauthId OAuth row ID, zero when the row was not found.
	 * @return array{providerErrorCode: string, oauthId: int}
	 */
	private static function buildRenewFailureLogContext(string $providerErrorCode, int $oauthId): array
	{
		return [
			'providerErrorCode' => $providerErrorCode,
			'oauthId' => $oauthId,
		];
	}

	/**
	 * The provider error code is known only to the service specific layer; a service that does
	 * not report one leaves the refusal unexplained, which is still a refusal.
	 *
	 * @return string
	 */
	protected function getProviderRefreshErrorCode(): string
	{
		return '';
	}

	/**
	 * Sets token, refresh token and token expiration for the OAuth entity.
	 *
	 * @param array{
	 *     TOKEN: string|null,
	 *     REFRESH_TOKEN: string,
	 *     TOKEN_EXPIRES?: int
	 * } $tokenData Array with token data.
	 * @return void
	 */
	private function setOAuthEntityTokens(array $tokenData): void
	{
		$this->oauthEntity->setToken($tokenData['TOKEN']);
		$this->oauthEntity->setRefreshToken($tokenData['REFRESH_TOKEN']);
		$this->oauthEntity->setAccessTokenExpires((int)($tokenData['TOKEN_EXPIRES'] ?? 0));
	}

	/**
	 * Returns token by instance UID
	 *
	 * @param string $uid Instance UID.
	 * @param int $expireGapSeconds Gap needed for using token, to ensure when we use token it still alive
	 *
	 * @return string|null
	 */
	public function getStoredToken($uid = null, int $expireGapSeconds = 10)
	{
		$token = null;

		if (!empty($uid))
		{
			$this->storedUid = $uid;
		}

		$item = $this->fetchStoredToken();

		if (!empty($item))
		{
			$this->setOAuthEntityTokens($item);

			$expireThreshold = time() + $expireGapSeconds;

			$token = $item['TOKEN'];

			if (empty($token) || $item['TOKEN_EXPIRES'] > 0 && $item['TOKEN_EXPIRES'] < $expireThreshold)
			{
				// The branch is entered by the freshness gap, not by the expiry itself, so a refused
				// renewal still hands out the stored token while its lifetime is not over - and
				// nothing once it is.
				$token = $this->renewTokens() ? $this->oauthEntity->getToken() : self::tokenStillAlive($item);
			}
		}

		return $token;
	}

	/**
	 * Obtains tokens from service
	 *
	 * @param string $code Service authorization code.
	 * @return boolean
	 */
	public function getAccessToken($code = null)
	{
		if ($code)
		{
			$this->oauthEntity->setCode($code);
		}

		$oauthData = $_SESSION['OAUTH_DATA'];

		$result = $this->oauthEntity->getAccessToken($this->getRedirect(false));

		$_SESSION['OAUTH_DATA'] = $oauthData;

		return $result;
	}

	/**
	 * Returns user data
	 *
	 * @param boolean $secure Strip raw data (includes tokens).
	 * @return array|null
	 */
	public function getUserData($secure = true)
	{
		try
		{
			$userData = $this->oauthEntity->getCurrentUser();
		}
		catch (Main\SystemException $e)
		{
		}

		if (!empty($userData))
		{
			return array_merge(
				$this->mapUserData($userData),
				$secure ? array() : array('__data' => $userData)
			);
		}
	}

	/**
	 * Returns unified user data
	 *
	 * @param array $userData User data.
	 * @return array
	 */
	abstract protected function mapUserData(array $userData);

	/**
	 * Returns service name
	 *
	 * @throws \Bitrix\Main\ObjectException
	 * @return string
	 */
	public static function getServiceName()
	{
		throw new Main\ObjectException('abstract');
	}

	/**
	 * Convert expires to unixtime, because it can be interval like 3600 seconds or date
	 *
	 * @param int $intervalOrTime Unixtime or seconds to expire
	 *
	 * @return int
	 */
	public static function convertTokenExpiresToUnixtimeIfNeed(int $intervalOrTime): int
	{
		$year = 60 * 60 * 24 * 365;
		$maxExpectedLifetime = 5 * $year;
		$isInterval = $intervalOrTime <= $maxExpectedLifetime;

		return $isInterval ? $intervalOrTime + time() : $intervalOrTime;
	}

    public function saveResponse($state): bool
	{
		$this->storedUid = $state['uid'];

		if ($item = $this->fetchStoredToken())
		{
			$this->oauthEntity->setRefreshToken($item['REFRESH_TOKEN']);
		}

		if (!empty($_REQUEST['code']) && \CSocServAuthManager::checkUniqueKey())
		{
			$this->getAccessToken($_REQUEST['code']);

			if ($userData = $this->getUserData(false))
			{
				$fields = [
					'UID' => $this->getStoredUid(),
					'TOKEN' => $userData['__data']['access_token'],
					'REFRESH_TOKEN' => $userData['__data']['refresh_token'],
					'TOKEN_EXPIRES' => $userData['__data']['expires_in'],
				];

				if (empty($item))
				{
					Mail\Internals\OAuthTable::add($fields);
				}
				else
				{
					Mail\Internals\OAuthTable::update($item['ID'], $fields);
				}

				$userDataObject = new UserData(
					(string) $userData['email'],
					(string) $userData['first_name'],
					(string) $userData['last_name'],
					(string) $userData['full_name'],
					(string) $userData['image']
				);

				if (isset($userData['__data']['emailIsIntended']))
				{
					$userDataObject->setEmailIsIntended((bool)$userData['__data']['emailIsIntended']);
				}
				else
				{
					$userDataObject->setEmailIsIntended(false);
				}

				if (isset($userData['__data']['userPrincipalName']))
				{
					$userDataObject->setUserPrincipalName((string)$userData['__data']['userPrincipalName']);
				}

				$this->setPublicUserData($userDataObject);

				return true;
			}
		}

        return false;
	}

	protected function setPublicUserData(UserData $userData): void
	{
		$this->publicUserData = $userData;
	}

	public function getPublicUserData(): UserData
	{
		return $this->publicUserData;
	}

	/**
	 * Maps a provider error response to one of the ERROR_SCENARIO_* cases.
	 *
	 * A provider specific code found in the description wins over the top-level error code:
	 * Microsoft keeps the latter generic and names the cause only in the description. Unknown
	 * input falls back to the generic scenario: an unexplained failure is still a failure the
	 * user has to be told about.
	 *
	 * @param string $errorCode Provider `error` parameter.
	 * @param string $errorDescription Provider `error_description` parameter, used only to look up a provider code.
	 * @return string One of the ERROR_SCENARIO_* constants.
	 */
	public static function classifyProviderError(string $errorCode, string $errorDescription = ''): string
	{
		// Scenarios whose text names Microsoft are reachable only by codes Microsoft alone
		// sends: `admin_consent_required` and the AADSTS numbers below. The rest of the map is
		// standard (RFC 6749, OpenID Connect), so any of the five providers with external
		// authorization can send it - and for them `unauthorized_client` usually means our own
		// application is misconfigured, not that a tenant administrator blocked access. Such a
		// refusal therefore goes to the generic scenario, whose text names no provider.
		// Microsoft names the real cause in the description and keeps the top-level code generic:
		// an application awaiting administrator approval comes back as
		// `access_denied` + `AADSTS90094: The grant requires admin permission`. Therefore a
		// recognized provider code is resolved FIRST - read the other way round, the most common
		// refusal of this task would pass for a user closing the window: silently, with no hint
		// and no counter. Codes we do not know (`AADSTS65004` - the user really did decline)
		// leave the decision to the top-level code below.
		$scenarioByProviderCode = [
			'65001' => self::ERROR_SCENARIO_ADMIN_CONSENT,
			'90094' => self::ERROR_SCENARIO_ADMIN_CONSENT,
			'900941' => self::ERROR_SCENARIO_ADMIN_CONSENT,
			'7000112' => self::ERROR_SCENARIO_BLOCKED_BY_ADMIN,
		];

		if (preg_match('/AADSTS(\d+)/i', $errorDescription, $matches)
			&& isset($scenarioByProviderCode[$matches[1]]))
		{
			return $scenarioByProviderCode[$matches[1]];
		}

		// Scenarios whose text names Microsoft are reachable only by codes Microsoft alone
		// sends: `admin_consent_required` and the AADSTS numbers above. The rest of the map is
		// standard (RFC 6749, OpenID Connect), so any of the five providers with external
		// authorization can send it - and for them `unauthorized_client` usually means our own
		// application is misconfigured, not that a tenant administrator blocked access. Such a
		// refusal therefore goes to the generic scenario, whose text names no provider.
		$scenarioByCode = [
			'access_denied' => self::ERROR_SCENARIO_CANCELLED,
			'admin_consent_required' => self::ERROR_SCENARIO_ADMIN_CONSENT,
			'invalid_grant' => self::ERROR_SCENARIO_RETRY,
			'interaction_required' => self::ERROR_SCENARIO_RETRY,
			'login_required' => self::ERROR_SCENARIO_RETRY,
		];

		$code = mb_strtolower(trim($errorCode));

		return $scenarioByCode[$code] ?? self::ERROR_SCENARIO_GENERIC;
	}

	/**
	 * Returns the message shown for a scenario, or null when the scenario is silent.
	 *
	 * Provider wording never reaches the user: the text comes from our language files only.
	 *
	 * @param string $scenario One of the ERROR_SCENARIO_* constants.
	 * @return array{title: string, text: string}|null
	 */
	public static function getErrorScenarioMessage(string $scenario): ?array
	{
		if ($scenario === self::ERROR_SCENARIO_CANCELLED)
		{
			return null;
		}

		// Phrase keys are spelled out, not built from the scenario id: a composed key is
		// invisible both to a developer searching the code and to the phrase linter.
		$phraseKeys = [
			self::ERROR_SCENARIO_ADMIN_CONSENT => [
				'MAIL_OAUTH_ERROR_ADMIN_CONSENT_TITLE',
				'MAIL_OAUTH_ERROR_ADMIN_CONSENT_TEXT',
			],
			self::ERROR_SCENARIO_BLOCKED_BY_ADMIN => [
				'MAIL_OAUTH_ERROR_BLOCKED_BY_ADMIN_TITLE',
				'MAIL_OAUTH_ERROR_BLOCKED_BY_ADMIN_TEXT',
			],
			self::ERROR_SCENARIO_RETRY => [
				'MAIL_OAUTH_ERROR_RETRY_TITLE',
				'MAIL_OAUTH_ERROR_RETRY_TEXT',
			],
			self::ERROR_SCENARIO_GENERIC => [
				'MAIL_OAUTH_ERROR_GENERIC_TITLE',
				'MAIL_OAUTH_ERROR_GENERIC_TEXT',
			],
		];

		[$titleKey, $textKey] = $phraseKeys[$scenario] ?? $phraseKeys[self::ERROR_SCENARIO_GENERIC];

		return [
			'title' => (string)Loc::getMessage($titleKey),
			'text' => (string)Loc::getMessage($textKey),
		];
	}

	/**
	 * Reduces a provider error code to an identifier before it is shown or handed out.
	 *
	 * The value comes from the request, so without this it could carry arbitrary text into
	 * the portal interface under the guise of an error code.
	 *
	 * @param string $errorCode Provider `error` parameter.
	 * @return string
	 */
	public static function sanitizeProviderErrorCode(string $errorCode): string
	{
		return mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $errorCode), 0, 64);
	}

	/**
	 * Reports a failed authorization to the opener and closes the window.
	 *
	 * Nothing is shown here: the window is a transport, and the reason belongs to the
	 * connect form, which shows it as a hint next to the button. Cancelled attempts report
	 * nothing at all: the form unblocks itself on the closed window.
	 *
	 * @param array $state Response data.
	 * @param string $errorCode Provider `error` parameter.
	 * @param string $errorDescription Provider `error_description` parameter.
	 * @return void
	 */
	public function renderError(array $state, string $errorCode, string $errorDescription = ''): void
	{
		$this->storedUid = (string)($state['uid'] ?? '');

		$scenario = self::classifyProviderError($errorCode, $errorDescription);
		$message = self::getErrorScenarioMessage($scenario);

		// The code is shown to the user and handed to the client, so it is reduced to an
		// identifier first: a provider code looks like `admin_consent_required` or
		// `AADSTS65001`, and anything else would be arbitrary text displayed as ours.
		$errorCode = self::sanitizeProviderErrorCode($errorCode);

		if ($message !== null && $errorCode !== '')
		{
			// The hint renders the text as markup (ui.tour sets innerHTML), so the provider
			// code: the only value here that comes from outside - is escaped as html.
			$message['text'] .= '<br>' . Loc::getMessage(
				'MAIL_OAUTH_ERROR_CODE',
				['#CODE#' => htmlspecialcharsbx($errorCode)]
			);
		}
		?>

		<script>

			(function() {

				var targetWindow = window.opener;

				<?php if ($message !== null): ?>
					if (targetWindow && targetWindow.BX && targetWindow.BX.onCustomEvent)
					{
						targetWindow.BX.onCustomEvent(
							'OnMailOAuthBError',
							[
								'<?=\CUtil::jsEscape($this->getStoredUid()) ?>',
								'<?=\CUtil::jsEscape($scenario) ?>',
								'<?=\CUtil::jsEscape($message['title']) ?>',
								'<?=\CUtil::jsEscape($message['text']) ?>',
								'<?=\CUtil::jsEscape($errorCode) ?>'
							]
						);
					}
				<?php endif; ?>

				// Let the opener handle the event before the window goes away.
				setTimeout(function() { window.close(); }, 100);

			})();

		</script>

		<?php
	}

	/**
	 * Handles service response
	 *
	 * @param array $state Response data.
	 * @return void
	 */
	public function handleResponse(array $state, $context = self::WEB_TYPE): void
	{
        if ($this->saveResponse($state))
        {
			if ($context === self::WEB_TYPE)
			{
				?>

				<script>

					targetWindow = window.opener ? window.opener : window;

					targetWindow.BX.onCustomEvent(
						'OnMailOAuthBCompleted',
						[
							'<?=\CUtil::jsEscape($this->getStoredUid()) ?>',
							'<?=\CUtil::jsEscape($this->getUrl()) ?>',
							<?=$this->getPublicUserData()->getJson() ?>
						]
					);

					if (targetWindow !== window)
					{
						window.close();
					}

				</script>

				<?
			}
			else if ($context === self::MOBILE_TYPE)
			{
				$params = http_build_query([
					'storedUid' => \CUtil::jsEscape($this->getStoredUid()),
					'email' => $this->getPublicUserData()->getEmail(),
				]);
				header('Location: bitrix24://?'.$params);
			}
        }
		else
		{
			// The provider returned a code but the token exchange did not produce tokens:
			// without this branch the response body stays empty and the user sees a blank window.
			$this->renderError($state, 'token_exchange_failed');
		}
    }
}

<?php

namespace Bitrix\Mail\Helper\OAuth;

use Bitrix\Main;
use Bitrix\Mail;
use Bitrix\Main\ArgumentException;

class Office365 extends Mail\Helper\OAuth
{

	protected function __construct()
	{
		$this->oauthEntity = new Office365Interface(
			\CSocServOffice365OAuth::getOption('office365_appid'),
			\CSocServOffice365OAuth::getOption('office365_appsecret')
		);

		// get graph universal scopes, for user profile read access
		$this->oauthEntity->setScope($this->oauthEntity->getGraphScopes());
	}

	protected function check()
	{
		$provider = new \CSocServOffice365OAuth;

		return $provider->checkSettings();
	}

	protected function mapUserData(array $userData)
	{
		return array(
			'email' => $userData['email'],
			'first_name' => $userData['first_name'],
			'last_name' => $userData['last_name'],
			'full_name' => $userData['name'],
			'image' => $userData['image'],
			'error' => $userData['error_description'],
			'userPrincipalName' => $userData['userPrincipalName'] ?? '',
		);
	}

	public static function getServiceName()
	{
		return 'office365';
	}

	public function getControllerUrl()
	{
		return \CSocServOffice365OAuth::getControllerUrl();
	}

	protected function getProviderRefreshErrorCode(): string
	{
		return $this->oauthEntity->getLastRefreshProviderErrorCode();
	}

}

if (Main\Loader::includeModule('socialservices'))
{
	class_exists('CSocServOffice365OAuth');

	class Office365Interface extends \COffice365OAuthInterface
	{
		/**
		 * Version of outlook resource api (part of url)
		 */
		const OUTLOOK_API_VERSION = "/v2.0";

		/**
		 * Resource for outlook token
		 */
		const OUTLOOK_RESOURCE = "https://outlook.office.com/api";

		private string $lastRefreshProviderErrorCode = '';

		public function getStorageTokens()
		{
			return false;
		}

		/**
		 * Provider error code of the refused first step, reduced to an identifier.
		 *
		 * @return string
		 */
		public function getLastRefreshProviderErrorCode(): string
		{
			return $this->lastRefreshProviderErrorCode;
		}

		/**
		 * Renews the mail token in two steps: the mail scopes first, the legacy request second.
		 *
		 * A tenant that does not consent to the mail scopes refuses the first step, and the
		 * second step reproduces today's scope-less request literally - so the worst outcome of
		 * the sequence equals the current behaviour. Refusal reasons are not told apart: the
		 * taxonomy of Microsoft error codes is not ours to rely on.
		 *
		 * @param string $refreshToken Stored refresh token.
		 * @param int $userId Socialservices user ID, used only by the base persistence.
		 * @param bool $save Persist the token into the socialservices user table.
		 * @return bool
		 */
		public function getNewAccessToken($refreshToken, $userId = 0, $save = false)
		{
			// The code belongs to the call that is starting, so no path of it keeps the code of
			// the previous one - the persisting branch below included.
			$this->lastRefreshProviderErrorCode = '';

			if ($save)
			{
				// Persisting into the socialservices user table stays base behaviour: the mail
				// flow stores tokens itself through Bitrix\Mail\Helper\OAuth.
				return parent::getNewAccessToken($refreshToken, $userId, $save);
			}

			if (empty($this->appID) || empty($this->appSecret) || (string)$refreshToken === '')
			{
				return false;
			}

			if (self::isMailScopesRequestEnabled())
			{
				$scopedResponse = $this->requestTokenByRefreshToken($refreshToken, $this->getOutlookScopes());

				if ($this->applyTokenResponse($scopedResponse))
				{
					return true;
				}

				$this->lastRefreshProviderErrorCode = self::readProviderErrorCode($scopedResponse);
			}

			$legacyResponse = $this->requestTokenByRefreshToken($refreshToken);

			if ($this->applyTokenResponse($legacyResponse))
			{
				return true;
			}

			// The refusal handed out is the one of the terminal step: a first step refused over
			// the scopes says nothing about a refresh token the second step found revoked. A
			// terminal answer that names no code at all leaves the code of the first step, which
			// is then the only thing known about the refusal.
			$this->lastRefreshProviderErrorCode = self::readProviderErrorCode($legacyResponse)
				?: $this->lastRefreshProviderErrorCode;

			return false;
		}

		/**
		 * Whether the renewal asks for the mail scopes before the scope-less request.
		 *
		 * The option ~disable_microsoft_oauth_mail_scopes exists for a mass refusal of tenants:
		 * set to Y, it returns the renewal to the single request it made before, and does so
		 * without a deployment. Follows the option of the SMTP authorization of the same provider,
		 * Mail\Helper\Mailbox\MailboxConnector::isOauthSmtpEnabled().
		 *
		 * @return bool
		 */
		private static function isMailScopesRequestEnabled(): bool
		{
			return Main\Config\Option::get('mail', '~disable_microsoft_oauth_mail_scopes') !== 'Y';
		}

		/**
		 * Error code of an answer, taken only when the provider named it as a string: a code
		 * arriving as an array or an object names nothing usable.
		 *
		 * @param array $response Decoded response.
		 * @return string Code reduced to an identifier, empty when none was named.
		 */
		private static function readProviderErrorCode(array $response): string
		{
			$errorCode = $response['error'] ?? null;

			return is_string($errorCode) ? Mail\Helper\OAuth::sanitizeProviderErrorCode($errorCode) : '';
		}

		/**
		 * Use refresh token to get outlook scopes, compatible with graph scopes
		 *
		 * @return void
		 */
		private function refreshToOutlookAccessToken(): void
		{
			$this->refreshWithScopes($this->getOutlookScopes());
		}

		/**
		 * Outlook scopes, that give Outlook email address, instead of profile email address
		 *
		 * @return array|string[]
		 */
		public function getOutlookScopes(): array
		{
			return [
				'offline_access',
				'https://outlook.office.com/IMAP.AccessAsUser.All',
				'https://outlook.office.com/SMTP.Send',
			];
		}

		/**
		 * Get user profile name
		 *
		 * @param bool $isSwitchedAlready If function already run
		 *
		 * @return string
		 */
		private function getGraphPrincipalName(bool $isSwitchedAlready = false): string
		{
			$httpClient = new \Bitrix\Main\Web\HttpClient();
			$httpClient->setHeader("Authorization", "Bearer " . $this->access_token);
			$jsonResponse = $httpClient->get($this->resource . static::VERSION . static::CONTACTS_URL);
			try
			{
				$decoded = \Bitrix\Main\Web\Json::decode($jsonResponse);
				if (!empty($decoded['userPrincipalName']) && is_string($decoded['userPrincipalName']))
				{
					return $decoded['userPrincipalName'];
				}
				else if (!empty($decoded['error']) && !$isSwitchedAlready)
				{
					$this->refreshToGraphAccessToken();
					return $this->getGraphPrincipalName(true);
				}
			}
			catch (ArgumentException $e)
			{
				AddMessage2Log($e->getMessage(), 'mail', 2, true);
			}
			return '';
		}

		/**
		 * Use refresh token to get outlook scopes, compatible with graph scopes
		 *
		 * @return void
		 */
		private function refreshToGraphAccessToken(): void
		{
			$this->refreshWithScopes($this->getGraphScopes());
		}

		/**
		 * Refresh access token with specific scopes
		 *
		 * @param array|string[] $scopes Array of scopes
		 *
		 * @return void
		 */
		private function refreshWithScopes(array $scopes): void
		{
			if (empty($this->refresh_token))
			{
				return;
			}

			$this->applyTokenResponse($this->requestTokenByRefreshToken($this->refresh_token, $scopes));
		}

		/**
		 * Asks the token endpoint for a new token, optionally naming the scopes.
		 *
		 * @param string $refreshToken Refresh token.
		 * @param array|string[] $scopes Requested scopes; an empty list reproduces the base
		 *                               scope-less request.
		 * @return array Decoded response, empty when the endpoint answered nothing usable.
		 */
		private function requestTokenByRefreshToken(string $refreshToken, array $scopes = []): array
		{
			$requestBody = [
				'refresh_token' => $refreshToken,
				'client_id' => $this->appID,
				'client_secret' => $this->appSecret,
				'grant_type' => 'refresh_token',
			];

			if (!empty($scopes))
			{
				$requestBody['scope'] = implode(' ', $scopes);
			}

			return $this->decodeTokenResponse($this->postToTokenEndpoint($requestBody));
		}

		/**
		 * The single point where the token endpoint is called, so a test can replace the
		 * transport without touching the renewal logic.
		 *
		 * @param array $requestBody Request body.
		 * @return string|false Raw response body.
		 */
		protected function postToTokenEndpoint(array $requestBody): string|false
		{
			$httpClient = new Main\Web\HttpClient();

			return $httpClient->post(static::TOKEN_URL, $requestBody);
		}

		/**
		 * @param string|false $jsonResponse Raw response body.
		 * @return array
		 */
		private function decodeTokenResponse(string|false $jsonResponse): array
		{
			if ($jsonResponse === false || $jsonResponse === '')
			{
				return [];
			}

			try
			{
				$decoded = Main\Web\Json::decode($jsonResponse);
			}
			catch (ArgumentException)
			{
				// The record is ours alone, nothing of the exception goes into it. Today the core
				// reduces the exception to "JSON error: Syntax error [4]" before throwing it
				// (Main\Web\Json::checkException()), so this is a guard, not a leak being fixed: a
				// wording built from a trace would carry the refresh token, which stands as an
				// argument of the frames of the renewal (AC-010).
				AddMessage2Log('Office365: the token endpoint answered a body that is not JSON', 'mail', 0);

				return [];
			}

			return is_array($decoded) ? $decoded : [];
		}

		/**
		 * Takes the tokens of a successful response, keeping the stored refresh token when the
		 * response carries none: Microsoft does not repeat it on every answer, and an empty
		 * assignment would cost the mailbox its only way to renew.
		 *
		 * @param array $response Decoded response.
		 * @return bool True when the response carried an access token.
		 */
		private function applyTokenResponse(array $response): bool
		{
			if (empty($response['access_token']))
			{
				return false;
			}

			$this->access_token = (string)$response['access_token'];
			$this->accessTokenExpires = (int)($response['expires_in'] ?? 0);

			if (!empty($response['refresh_token']))
			{
				$this->refresh_token = (string)$response['refresh_token'];
			}

			return true;
		}

		/**
		 * Get Scopes for graph resource
		 *
		 * @return array|string[]
		 */
		public function getGraphScopes(): array
		{
			return [
				'User.read',
				'offline_access',
				'IMAP.AccessAsUser.All',
				'SMTP.Send',
			];
		}

		public function getTokenData(): array
		{
			return [
				'access_token' => $this->access_token,
				'refresh_token' => $this->refresh_token,
				'expires_in' => Mail\Helper\OAuth::convertTokenExpiresToUnixtimeIfNeed($this->accessTokenExpires),
			];
		}

		public function getCurrentUser()
		{
			if (empty($this->access_token))
			{
				return false;
			}
			$userPrincipalName = $this->getGraphPrincipalName();
			$this->refreshToOutlookAccessToken();

			$httpClient = new \Bitrix\Main\Web\HttpClient();
			$httpClient->setHeader("Authorization", "Bearer ". $this->access_token);

			$result = $httpClient->get(static::OUTLOOK_RESOURCE . static::OUTLOOK_API_VERSION . static::CONTACTS_URL);
			try
			{
				$result = \Bitrix\Main\Web\Json::decode($result);
			}
			catch (ArgumentException $e)
			{
				AddMessage2Log($e->getMessage(), 'mail', 2, true);
				$result = [];
			}

			if(isset($result['EmailAddress']))
			{
				$email = $result['EmailAddress'];
				$emailIsIntended = false;
			}
			else
			{
				global $USER;
				$email = $USER->GetEmail();
				if(is_null($email))
				{
					$email = '';
				}
				$emailIsIntended = true;
			}

			return array_merge(
				[
					'email' => $email,
					'emailIsIntended' => $emailIsIntended,
					'userPrincipalName' => $userPrincipalName,
				],
				$this->getTokenData()
			);
		}

	}
}

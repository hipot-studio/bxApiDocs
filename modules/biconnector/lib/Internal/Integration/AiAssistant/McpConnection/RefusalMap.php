<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * The refusal table of the adapter: thirteen branches, eleven of them contour codes and two decided
 * by the module itself.
 *
 * The three codes of the module access gate are not repacked here - they reach the popup as they
 * came. A contour code unknown to the table, one shipped after us, falls into the issue failure
 * branch.
 */
final class RefusalMap
{
	public const BI_MCP_AUTH_REQUIRED = 'BI_MCP_AUTH_REQUIRED';
	public const BI_MCP_PORTAL_DISABLED = 'BI_MCP_PORTAL_DISABLED';
	public const BI_MCP_ADMIN_DISABLED = 'BI_MCP_ADMIN_DISABLED';
	public const BI_MCP_SUBSCRIPTION_REQUIRED = 'BI_MCP_SUBSCRIPTION_REQUIRED';
	public const BI_MCP_NOT_EMPLOYEE = 'BI_MCP_NOT_EMPLOYEE';
	public const BI_MCP_SCOPES_DISABLED = 'BI_MCP_SCOPES_DISABLED';
	public const BI_MCP_NOT_CREATOR = 'BI_MCP_NOT_CREATOR';
	public const BI_MCP_CONSTRUCTOR_DISABLED = 'BI_MCP_CONSTRUCTOR_DISABLED';
	public const BI_MCP_MODULE_UNAVAILABLE = 'BI_MCP_MODULE_UNAVAILABLE';
	public const BI_MCP_UNSUPPORTED_DATABASE = 'BI_MCP_UNSUPPORTED_DATABASE';
	public const BI_MCP_ISSUE_FAILED = 'BI_MCP_ISSUE_FAILED';
	public const BI_MCP_UNAVAILABLE = 'BI_MCP_UNAVAILABLE';
	public const BI_MCP_SERVER_URL_MISSING = 'BI_MCP_SERVER_URL_MISSING';

	/**
	 * Contour codes are kept as literals: the master edition of the contour class declares none of
	 * them, so naming its constants would break the module on a portal without the full contract.
	 * A branch the module decides on its own has no contour code. The slug is the outcome parameter
	 * of the issue event; it carries no underscore, because the core rejects a parameter value with
	 * more than one.
	 */
	private const TABLE = [
		self::BI_MCP_AUTH_REQUIRED => [
			'contourCode' => 'MCP_BI_AUTH_REQUIRED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_AUTH_REQUIRED',
			'slug' => 'auth',
		],
		self::BI_MCP_PORTAL_DISABLED => [
			'contourCode' => 'MCP_BI_PORTAL_MCP_DISABLED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_PORTAL_DISABLED',
			'slug' => 'portaloff',
		],
		self::BI_MCP_ADMIN_DISABLED => [
			'contourCode' => 'MCP_BI_ADMIN_DISABLED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_ADMIN_DISABLED',
			'slug' => 'adminoff',
		],
		self::BI_MCP_SUBSCRIPTION_REQUIRED => [
			'contourCode' => 'MCP_BI_SUBSCRIPTION_REQUIRED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_SUBSCRIPTION_REQUIRED',
			'slug' => 'subscription',
		],
		self::BI_MCP_NOT_EMPLOYEE => [
			'contourCode' => 'MCP_BI_NOT_INTRANET_USER',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_NOT_EMPLOYEE',
			'slug' => 'notemployee',
		],
		self::BI_MCP_SCOPES_DISABLED => [
			'contourCode' => 'MCP_BI_TOKEN_SCOPES_DISABLED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_SCOPES_DISABLED',
			'slug' => 'scopesoff',
		],
		self::BI_MCP_NOT_CREATOR => [
			'contourCode' => 'MCP_BI_NOT_TOKEN_CREATOR',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_NOT_CREATOR',
			'slug' => 'notcreator',
		],
		self::BI_MCP_CONSTRUCTOR_DISABLED => [
			'contourCode' => 'MCP_BI_CONSTRUCTOR_DISABLED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_CONSTRUCTOR_DISABLED',
			'slug' => 'constructoroff',
		],
		self::BI_MCP_MODULE_UNAVAILABLE => [
			'contourCode' => 'MCP_BI_MODULE_UNAVAILABLE',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_MODULE_UNAVAILABLE',
			'slug' => 'nomodule',
		],
		self::BI_MCP_UNSUPPORTED_DATABASE => [
			'contourCode' => 'MCP_BI_UNSUPPORTED_DATABASE',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_UNSUPPORTED_DATABASE',
			'slug' => 'nodbsupport',
		],
		self::BI_MCP_ISSUE_FAILED => [
			'contourCode' => 'MCP_BI_ISSUE_FAILED',
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_ISSUE_FAILED',
			'slug' => 'failed',
		],
		self::BI_MCP_UNAVAILABLE => [
			'contourCode' => null,
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_UNAVAILABLE',
			'slug' => 'unavailable',
		],
		self::BI_MCP_SERVER_URL_MISSING => [
			'contourCode' => null,
			'phraseId' => 'BIC_MCP_CONNECTION_REFUSAL_SERVER_URL_MISSING',
			'slug' => 'noserverurl',
		],
	];

	/**
	 * @return string Branch of the contour code; a code the table does not know falls into the issue
	 * failure branch.
	 */
	public function resolveBranch(string $contourCode): string
	{
		foreach (self::TABLE as $branch => $row)
		{
			if ($row['contourCode'] === $contourCode)
			{
				return $branch;
			}
		}

		return self::BI_MCP_ISSUE_FAILED;
	}

	public function createError(string $branch): McpConnectionRefusalError
	{
		$branch = $this->normalize($branch);

		return McpConnectionRefusalError::refusal($this->getText($branch), $branch);
	}

	/**
	 * Only the issue failure branch invites another attempt: every other branch refuses for a reason
	 * that a repeat does not change, the unsupported database type above all.
	 */
	public function createReissueError(string $branch): McpConnectionRefusalError
	{
		$branch = $this->normalize($branch);
		if ($branch !== self::BI_MCP_ISSUE_FAILED)
		{
			return McpConnectionRefusalError::refusal($this->getText($branch), $branch);
		}

		return McpConnectionRefusalError::refusalAfterFailedReissue($this->getText($branch), $branch);
	}

	/**
	 * @return string|null Outcome parameter of the issue event; null for a code that is not a branch
	 * of the table, which the caller turns into its own default.
	 */
	public function getAnalyticsSlug(string $branch): ?string
	{
		$row = self::TABLE[$branch] ?? null;

		return $row === null ? null : $row['slug'];
	}

	/**
	 * @return string[] Every branch of the table.
	 */
	public function getBranches(): array
	{
		return array_keys(self::TABLE);
	}

	/**
	 * @return string[] Contour codes the table covers, for the test that compares them with the
	 * constants the contour declares.
	 */
	public function getContourCodes(): array
	{
		return array_values(array_filter(array_column(self::TABLE, 'contourCode')));
	}

	private function normalize(string $branch): string
	{
		return isset(self::TABLE[$branch]) ? $branch : self::BI_MCP_ISSUE_FAILED;
	}

	private function getText(string $branch): string
	{
		$row = self::TABLE[$branch] ?? self::TABLE[self::BI_MCP_ISSUE_FAILED];

		return (string)Loc::getMessage($row['phraseId']);
	}
}

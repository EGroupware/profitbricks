<?php
/**
 * EGroupware - DNS updates
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;

class profitbricks_dns
{
	/**
	 * Config of app
	 *
	 * @var array
	 */
	protected static $config;

	/**
	 * Update an DNS entry
	 *
	 * @param string $dnsname
	 * @param string $ip
	 * @param array& $headers =null on return http response headers
	 * @return string|boolean content returned by get request or false on error
	 */
	public static function update($dnsname, $ip, &$headers=null)
	{
		if (empty(self::$config['dnsupdateurl']) ||
			empty(self::$config['dnsupdateusername']) ||
			empty(self::$config['dnsupdatepassword']))
		{
			throw new Api\Exception\WrongUserinput('DNS update not configured!');
		}

		if (!preg_match('/^([a-z0-9-_]+\.)+[a-z]{2,}$/i', $dnsname))
		{
			throw new Api\Exception\WrongUserinput("Invalid DNS name '$dnsname'!");
		}
		$matches = null;
		if (!preg_match('/^(\d{1,3})(\.\d{1,3}){3}$/', $ip, $matches) || $matches[1] == '10')
		{
			throw new Api\Exception\WrongUserinput("Invalid IP4 address '$ip'!");
		}

		$url = strtr(self::$config['dnsupdateurl'], array(
			'$IP' => urlencode($ip),
			'$DNSNAME' => urlencode($dnsname),
		));
		$context = Api\Framework::proxy_context(self::$config['dnsupdateusername'], self::$config['dnsupdatepassword']);

		$response = file_get_contents($url, false, $context);
		$headers = $http_response_header;
		if ($response === false)
		{
			error_log(__METHOD__."('$dnsname', '$ip') GET $url response headers ".array2string($headers));
			error_log(__METHOD__."('$dnsname', '$ip') GET $url returned ".array2string($response));
		}
		return $response;
	}

	public static function init_static()
	{
		self::$config = Api\Config::read('profitbricks');
	}
}
profitbricks_dns::init_static();
<?php
/**
 * EGroupware - Profitbricks - API
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;

class profitbricks_api
{
	const APP = 'profitbricks';
	const CLOUD_API = 'https://api.ionos.com/cloudapi/v6/';
	const AUTH_API = 'https://api.ionos.com/auth/v1/';

	/**
	 * Config of app
	 *
	 * @var array
	 */
	protected static $config;

	/**
	 * Generate token to use instead of user credentials
	 *
	 * @param ?string $contract_number send as X-Contract-Number header, required for multiple contracts
	 * @return ?string
	 */
	static function tokenGenerate(string $contract_number=null)
	{
		$response = self::get(self::AUTH_API.'tokens/generate', null, !empty($contract_number) ? [
			'X-Contract-Number' => $contract_number,
		] : []);

		return $response['token'] ?? null;
	}

	/**
	 * Check if given JWT expires in the given time-span
	 *
	 * @param string $token
	 * @param string $min
	 * @param Api\DateTime|null $exp on return expiration date
	 * @return bool
	 * @throws Api\Exception
	 * @throws JsonException
	 */
	static function jwtExpires(string $token, string $min='2month', Api\DateTime &$exp=null)
	{
		list(, $payload) = explode('.', $token);
		$data = json_decode(base64_decode($payload), true, 512, JSON_THROW_ON_ERROR);

		$exp = new Api\DateTime($data['exp']);

		return $exp < new Api\DateTime($min);
	}

	/**
	 * Get datacenters
	 *
	 * @param boolean $just_names =false return just id => name pairs
	 * @return array
	 * @throws Api\Exception\NotFound
	 */
	static function datacenters($just_names=false)
	{
		// cache datacenters in session
		$items = Api\Cache::getSession('profitbricks', __FUNCTION__, function()
		{
			return self::get('datacenters', 1);
		});

		if ($just_names)
		{
			$names = array();
			foreach($items as $item)
			{
				$names[$item['id']] = $item['properties']['name'];
			}
			return $names;
		}
		return $items;
	}

	/**
	 * Get servers in datacenter incl. further information (see $depth param)
	 *
	 * @param string $datacenter id of datacenter
	 * @param int $depth =1 e.g. 3 for nics incl. ip
	 * @return array
	 * @throws Api\Exception\NotFound
	 */
	static function servers($datacenter, $depth=1)
	{
		return self::get('datacenters/'.$datacenter.'/servers', $depth);
	}

	/**
	 * Get single server in datacenter incl. further information (see $depth param)
	 *
	 * @param string $datacenter id of datacenter
	 * @param string $server id of server
	 * @param int $depth =1 eg. 2 for nics incl. ip
	 * @return array
	 * @throws Api\Exception\NotFound
	 */
	static function server($datacenter, $server, $depth=1)
	{
		return self::get('datacenters/'.$datacenter.'/servers/'.$server, $depth);
	}

	/**
	 * Post action to API
	 *
	 * @param string $what eg. "datacenters/$dc/servers/$server/$action"
	 * @param array|string $body =null array get automatic www-form-urlencoded and send with content-type
	 * @param array $header =array()
	 * @param string& $response_body =null on return response body
	 * @return array with items
	 * @throws Api\Exception\NotFound
	 */
	static function post($what, $body=null, $header=array(), &$response_body=null)
	{
		$url = self::CLOUD_API.$what;

		if (is_array($body))
		{
			$body = http_build_query($body);
			$header['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		if (!($f = self::open($url, 'POST', $body, $header)) ||
			!($response = stream_get_contents($f)))
		{
			error_log("Request to '$url' failed: ".$response);
			if ($f) fclose($f);
			throw new Api\Exception\NotFound("Request to '$url' failed!");
		}
		if ($f) fclose($f);

		$response_headers = array();
		$response_body = self::parse_response($response, $response_headers);
		//error_log(__METHOD__."($what) POST to $url returned ".array2string($response_headers));

		return $response_headers;
	}

	/**
	 * Get items from API
	 *
	 * @param string $what eg. "datacenters" or "datacenters/$id/servers" or full URL eg. self::AUTH_API.'token/generate'
	 * @param ?int $depth =0 added as get parameter, if NOT null
	 * @return array with items, for collection or just response data otherwise
	 * @throws Api\Exception\NotFound
	 */
	protected static function get(string $what, ?int $depth=0, array $headers=[])
	{
		$url = (substr($what, 0,8) === 'https://'?'':self::CLOUD_API).
			$what.(isset($depth)?'?depth='.$depth:'');

		if (!($f = self::open($url, 'GET', '', $headers)) ||
			!($response = stream_get_contents($f)) ||
			!($json = self::parse_response($response)) ||
			!($data = json_decode($json, true)) ||
			empty($data['type']) && empty($data['token']) ||
			$data['type'] == 'collection' && (!isset($data['items']) || !is_array($data['items'])))
		{
			error_log("Request to '$url' failed: ".$response);
			if ($f) fclose($f);
			throw new Api\Exception\NotFound("Request to '$url' failed!");
		}
		if ($f) fclose($f);

		return $data['type'] == 'collection' ? $data['items'] : $data;
	}

	/**
	 * Open HTTP request
	 *
	 * @param string|array $url string with url or already passed like return from parse_url
	 * @param string $method ='GET'
	 * @param string $body =''
	 * @param array $header =array() additional header like array('Authentication' => 'basic xxxx')
	 * @param float $timeout =2 0 for async connection
	 * @return resource|boolean socket still in blocking mode
	 */
	protected static function open($url, $method='GET', $body='', array $header=array(), $timeout=2)
	{
		if (empty($header['Authorization']) &&
			(empty(self::$config['username']) || empty(self::$config['password'])) &&
			empty(self::$config['token']))
		{
			Api\Egw::redirect_link('/index.php','menuaction=admin.admin_config.index&appname=profitbricks&ajax=true', 'admin');
		}
		// add default authentication header
		$header += ['Authorization' => !empty(self::$config['token']) ? 'Bearer '.self::$config['token'] :
			'Basic '.base64_encode(self::$config['username'].':'.self::$config['password'])];

		$parts = is_array($url) ? $url : parse_url($url);
		$addr = ($parts['scheme'] == 'https'?'ssl://':'tcp://').$parts['host'].':';
		$addr .= isset($parts['port']) ? (int)$parts['port'] : ($parts['scheme'] == 'https' ? 443 : 80);
		$errno = $errstr = null;
		if (!($sock = stream_socket_client($addr, $errno, $errstr, $timeout,
			$timeout ? STREAM_CLIENT_CONNECT : STREAM_CLIENT_ASYNC_CONNECTC)))
		{
			error_log(__METHOD__."('$url', ...) stream_socket_client('$addr', ...) $errstr ($errno)");
			return false;
		}
		$request = $method.' '.$parts['path'].(empty($parts['query'])?'':'?'.$parts['query'])." HTTP/1.1\r\n".
			"Host: ".$parts['host'].(empty($parts['port'])?'':':'.$parts['port'])."\r\n".
			"User-Agent: ".__CLASS__."\r\n".
			"Accept: application/json\r\n".
			"Cache-Control: no-cache\r\n".
			"Pragma:no-cache\r\n".
			"Connection: close\r\n";

		// Content-Length header is required for methods containing a body
		if (in_array($method, array('PUT','POST','PATCH')))
		{
			$header['Content-Length'] = strlen($body);
		}
		foreach($header as $name => $value)
		{
			$request .= $name.': '.$value."\r\n";
		}
		$request .= "\r\n";
		//if ($method != 'GET') error_log($request.$body);

		if (fwrite($sock, $request.$body) === false)
		{
			error_log(__METHOD__."('$url', ...) error sending request!");
			fclose($sock);
			return false;
		}
		return $sock;
	}

	/**
	 * Parse body from HTTP response and dechunk it if necessary
	 *
	 * @param string $response
	 * @param array& $headers =null headers on return, lowercased name => value pairs
	 * @return string body of response
	 */
	protected static function parse_response($response, array &$headers=null)
	{
		list($header, $body) = explode("\r\n\r\n", $response, 2);
		$headers = array();
		foreach(explode("\r\n", $header) as $line)
		{
			$parts = preg_split('/:\s*/', $line, 2);
			if (count($parts) == 2)
			{
				$headers[strtolower($parts[0])] = $parts[1];
			}
			else
			{
				$headers[] = $parts[0];
			}
		}
		// dechunk body if necessary
		if (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] == 'chunked')
		{
			$chunked = $body;
			$body = '';
			while($chunked && (list($size, $chunked) = explode("\r\n", $chunked, 2)) && $size)
			{
				$body .= substr($chunked, 0, hexdec($size));
				if (true) $chunked = substr($chunked, hexdec($size)+2);	// +2 for "\r\n" behind chunk
			}
		}
		return $body;
	}

	public static function init_static()
	{
		self::$config = Api\Config::read(self::APP);

		// generate or renew token, if it's about to expire
		if (!empty(self::$config['username']) && !empty(self::$config['password']) && empty(self::$config['token']) ||
			!empty(self::$config['token']) && self::jwtExpires(self::$config['token'], '2month'))
		{
			if (($token = self::tokenGenerate()))
			{
				Api\Config::save_value('token', self::$config['token']=$token, self::APP);
				Api\Config::save_value('password', self::$config['password']=null, self::APP);
			}
		}
	}
}
profitbricks_api::init_static();
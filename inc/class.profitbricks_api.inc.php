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
	const URL = 'https://api.profitbricks.com/cloudapi/v3/';

	/**
	 * Config of app
	 *
	 * @var array
	 */
	protected static $config;

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
	 * @param int $depth =1 eg. 3 for nics incl. ip
	 * @return type
	 * @throws Api\Exception\NotFound
	 */
	static function servers($datacenter, $depth=1)
	{
		return self::get('datacenters/'.$datacenter.'/servers', $depth);
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
		$url = self::URL.$what;

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
	 * @param string $what eg. "datacenters" or "datacenters/$id/servers"
	 * @param int $depth =0
	 * @return array with items
	 * @throws Api\Exception\NotFound
	 */
	protected static function get($what, $depth=0)
	{
		$url = self::URL.$what.'?depth='.(int)$depth;

		if (!($f = self::open($url)) ||
			!($response = stream_get_contents($f)) ||
			!($json = self::parse_response($response)) ||
			!($data = json_decode($json, true)) ||
			!isset($data['items']) || !is_array($data['items']))
		{
			error_log("Request to '$url' failed: ".$response);
			if ($f) fclose($f);
			throw new Api\Exception\NotFound("Request to '$url' failed!");
		}
		if ($f) fclose($f);

		return $data['items'];
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
			empty(self::$config['username']) || empty(self::$config['password']))
		{
			Api\Egw::redirect_link('/index.php','menuaction=admin.admin_config.index&appname=profitbricks&ajax=true', 'admin');
		}
		// add default authentication header
		$header += array('Authorization' => 'Basic '.base64_encode(self::$config['username'].':'.self::$config['password']));

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
		self::$config = Api\Config::read('profitbricks');
	}
}
profitbricks_api::init_static();
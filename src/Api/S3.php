<?php
/**
 * EGroupware - IONOS Cloud API - creating user and S3 credentials and buckets
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2023 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Profitbricks\Api;

use EGroupware\Api;
use AsyncAws;

class S3
{
	/**
	 * Creating user and S3 credentials and buckets
	 *
	 * @param string $instance hostname of instance
	 * @param ?array &$user values for keys 'firstname', 'lastname', 'email', 'password', otherwise the following is derived from $instance
	 *  email: "s3@$instance", firstname: host-name, lastname: domain e.g. egroupware.de, password: random one generated
	 * @param ?string[] &$msgs
	 * @return array[] storage config array of array with values for keys 'endpoint', 'accessKeyId', 'accessKeySecret' and 'Bucket'
	 */
	static function create(string $instance, array $user=null, array &$msgs=null, string $group='S3customers')
	{
		if (!$user)
		{
			$parts = explode('.', $instance);
			$user = [
				'firstname' => array_shift($parts),
				'lastname'  => implode('.', $parts),
				'email'     => 's3@'.$instance,
			];
		}
		else
		{
			$user = array_intersect_key($user, array_flip(['firstname', 'lastname', 'email', 'password']));
		}
		if (empty($user['password']))
		{
			$user['password'] = Api\Auth::randomstring(20);
		}
		$msgs = [];
		try {
			$user = Cloud\User::get($user['email'], 2);
			$msgs[] = lang('User already exists.');
		}
		catch(Api\Exception\NotFound $e) {
			$user = Cloud\User::add([
					'administrator' => false,
					'active' => true,
				]+$user);
			$msgs[] = lang('User created.');
		}
		if (empty($user->entities['groups']['items']))
		{
			$user->addMembership($group = $group ?: 'S3customers');
			$msgs[] = lang('User added to group "%1"', $group);
		}
		$key = current($user->getS3keys());
		$instanceShort = self::instanceShortcut($instance);
		$storages = [];
		$buckets = null;
		// AsyncAWS does NOT validate IONOS LocationConstraint / regions,
		// so we have to overwrite / alias the constraint class
		class_alias(AlwaysExists::class, 'AsyncAws\S3\Enum\BucketLocationConstraint');
		foreach([
	        'de' => 'https://s3-eu-central-1.ionoscloud.com',
	        'eu-central-2' => 'https://s3-eu-central-2.ionoscloud.com',
        ] as $region => $endpoint)
		{
			$s3 = new AsyncAws\S3\S3Client([
				'endpoint' => $endpoint,
				'accessKeyId' => $key->id,
				'accessKeySecret' => $key->secretKey,
				'region' => $region,
			]);
			if (!isset($buckets))
			{
				$request = $s3->listBuckets([]);
				$buckets = [];
				foreach ($request->getBuckets() as $bucket)
				{
					$buckets[] = $bucket->getName();
				}
			}
			$bucket = $instanceShort . (substr($region, -1) === '2' ? '2' : '');

			if (!in_array($bucket, $buckets))
			{
				$request = $s3->createBucket([
					'Bucket' => $bucket,
					'CreateBucketConfiguration' => [
						'LocationConstraint' => $region,
					],
					'@region' => $region,
				]);
				$request->resolve();
			}
			$storages[] = [
				'endpoint' => $endpoint,
				'accessKeyId' => $key->id,
				'accessKeySecret' => $key->secretKey,
				'Bucket' => $bucket,
			];
		}
		return $storages;
	}

	/**
	 * Generate a shortcut (without "."!) for a fully qualified instance-/host-name
	 *
	 * @param string $instance
	 * @return string
	 */
	static function instanceShortcut(string $instance) : string
	{
		return str_replace('.', '-',
			preg_replace('/^([^.]+)\.egroupware(-italia)?\.(.*)$/', '$1-$3', $instance));
	}
}

/**
 * Class with static method exists, always returning true
 */
class AlwaysExists
{
	static function exists($v)
	{
		return true;
	}
}
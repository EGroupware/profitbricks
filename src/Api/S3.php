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
	static function create(string $instance, ?array $user=null, ?array &$msgs=null, string $group='S3customers')
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
			$user = Cloud\User::get($user['email'], 3);
			$msgs[] = lang('User already exists.');
		}
		catch(Api\Exception\NotFound $e) {
			$user = Cloud\User::add([
					'administrator' => false,
					'active' => true,
				]+$user);
			$msgs[] = lang('User created.');
		}
		if (!$group) $group = 'S3customers';
		if (!array_filter($user->entities['groups']['items'] ?? [], static function($item) use ($group)
		{
			return $item['properties']['name'] === $group;
		}) && $user->addMembership($group))
		{
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

	/**
	 * Delete instance user incl. S3 buckets
	 *
	 * @param string $instance instance domain e.g. test.egroupware.de
	 * @param array $s3_storages configured s3 storages
	 * @throws Api\Exception\NotFound
	 */
	static function delete(string $instance, array $s3_storages=[], int $retry=2)
	{
		$user = Cloud\User::get('s3@'.$instance);

		// AsyncAWS does NOT validate IONOS LocationConstraint / regions,
		// so we have to overwrite / alias the constraint class
		class_alias(AlwaysExists::class, 'AsyncAws\S3\Enum\BucketLocationConstraint');

		// explicitly delete first two buckets (not 3rd user supplied one!)
		foreach(array_slice($s3_storages, 0, 2) as $storage)
		{
			unset($file);
			foreach([
		        'de' => 'https://s3-eu-central-1.ionoscloud.com',
		        'eu-central-2' => 'https://s3-eu-central-2.ionoscloud.com',
	        ] as $region => $endpoint)
			{
				if ($storage['endpoint'] === $endpoint)
				{
					$s3 = new AsyncAws\S3\S3Client([
						'endpoint' => $endpoint,
						'accessKeyId' => $storage['accessKeyId'],
						'accessKeySecret' => $storage['accessKeySecret'],
						'region' => $region,
					]);
					try {
						$request = $s3->listObjectsV2([
							'Bucket' => $storage['Bucket'],
						]);
						$objects = [];
						/** @var $file AwsObject */
						foreach($request->getIterator() as $file)
						{
							$objects[] = ['Key' => $file->getKey()];
							if (count($objects) === 1000)
							{
								$s3->deleteObjects([
									'Bucket' => $storage['Bucket'],
									'Delete' => [
										'Objects' => $objects,
									],
								])->resolve();
								$objects = [];
							}
						}
						if ($objects)
						{
							$s3->deleteObjects([
								'Bucket' => $storage['Bucket'],
								'Delete' => [
									'Objects' => $objects,
								],
							])->resolve();
						}
						// if we have just deleted some files, give it a little time, otherwise with get a 409 Bucket not empty
						if (isset($file))
						{
							sleep(1);
						}
						$s3->deleteBucket([
							'Bucket' => $storage['Bucket'],
						])->resolve();
					}
					catch(\Exception $e)
					{
						// ignore if bucket has already been deleted / does not exist
						if ($e->getCode() !== 404)
						{
							// we also sometimes get 409 BucketNotEmpty, even if we iterate over all files, to let's retry
							if ($retry > 0)
							{
								sleep(1);
								return self::delete($instance, $s3_storages, $retry-1);
							}
							$e->detail = $e->getAwsMessage().' ('.$e->getAwsCode().')';
							throw $e;
						}
					}
				}
			}
		}

		// finally delete the user
		$user->delete();
	}

	static function list(array $storage, ?string $start_after=null, int $rows=100)
	{
		// AsyncAWS does NOT validate IONOS LocationConstraint / regions,
		// so we have to overwrite / alias the constraint class
		class_alias(AlwaysExists::class, 'AsyncAws\S3\Enum\BucketLocationConstraint');
		foreach([
	        'de' => 'https://s3-eu-central-1.ionoscloud.com',
	        'eu-central-2' => 'https://s3-eu-central-2.ionoscloud.com',
        ] as $region => $endpoint)
		{
			if ($storage['endpoint'] === $endpoint)
			{
				$s3 = new AsyncAws\S3\S3Client([
					'endpoint' => $endpoint,
					'accessKeyId' => $storage['accessKeyId'],
					'accessKeySecret' => $storage['accessKeySecret'],
					'region' => $region,
				]);
				$objects = [];
				$request = $s3->listObjectsV2(array_filter([
					'Bucket' => $storage['Bucket'],
					'StartAfter' => $start_after,
					'MaxKeys' => $rows,
				]));
				/** @var $file AwsObject */
				foreach ($request->getIterator() as $file)
				{
					$objects[] = [
						'Key' => $file->getKey(),
						'Size' => $file->getSize(),
						'LastModified' => $file->getLastModified(),
					];
					if (count($objects) >= $rows) break;
				}
				return $objects;
			}
		}
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
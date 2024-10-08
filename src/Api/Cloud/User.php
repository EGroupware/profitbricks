<?php
/**
 * EGroupware - IONOS Cloud API user(s) class
 *
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017-23 by Ralf Becker <rb-AT-egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Profitbricks\Api\Cloud;

class User extends Base
{
	const BASE = 'um/users';
    const UNIQ_ATTR = 'email';

	protected string $firstname;
	protected string $lastname;
	protected string $email;
	protected string $password='not-available';
	protected bool $active=true;
	protected bool $administrator=false;
	protected bool $forceSecAuth;
	protected bool $secAuthActive;
	protected $entities;

	static protected array $properties = [
		'firstname' => 'string',
		'lastname'  => 'string',
		'email'     => 'string',
		'password'  => 'string',
		'administrator' => '?bool',
		'active'    => '?bool',
		/* not allowed for creating new users
		'forceSecAuth'  => '?bool',
		'secAuthActive' => '?bool',
        's3CanonicalUserId' => '?string',
		*/
	];
	static protected array $defaults = [
		'administrator' => false,
		'active'    => true,
	];

	/**
	 * Add a user
	 *
	 * @link https://api.ionos.com/docs/cloud/v6/#tag/User-management/operation/umUsersPost
	 * @param array $attrs
	 * @return static
	 * @throws \EGroupware\Api\Exception\NotFound
	 */
	public static function add(array $attrs): static
	{
		return parent::add($attrs);
	}

	/**
     * Get user by his UUID or email
     *
	 * @param string $id
	 * @param int $depth
	 * @return static
	 * @throws \EGroupware\Api\Exception\NotFound
	 */
    public static function get(string $id, int $depth=1): static
    {
        return parent::get($id, $depth);
    }

	/**
	 * Add user to group
	 *
	 * @param $group group-name or -ID
	 * @return bool true added, false already a member
	 */
	public function addMembership($group)
	{
		$group = Group::get($group);
		foreach($this->entities['groups']['items'] as $item)
		{
			if ($group->id === $item['id'])
			{
				return false;
			}
		}
		$group->addMember($this);
		return true;
	}

	/**
	 * Get s3 keys of the user
	 *
	 * @link https://api.ionos.com/docs/cloud/v6/#tag/User-S3-keys/operation/umUsersS3keysGet
	 * @return S3key[]
	 * @throws Api\Exception\NotFound
	 */
	public function getS3keys()
	{
		$keys = [];
		foreach($this->entities['s3Keys']['items'] ?: [self::call(self::BASE."/$this->id/s3keys", [], [], 'POST')] as $item)
		{
			$keys[] = new S3key($item);
		}
		return $keys;
	}
}
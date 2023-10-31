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

class S3key extends Base
{
	const BASE = 'um/users/{userId}/s3keys';

	protected string $secretKey;

	static protected array $properties = [
		'secretKey' => 'string',
	];
	static protected array $defaults = [];
}
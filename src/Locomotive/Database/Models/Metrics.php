<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package     Locomotive
 * @subpackage  Locomotive\Database
 */

namespace Locomotive\Database\Models;

use Illuminate\Database\Eloquent\Model;

class Metrics extends Model
{
	protected $guarded = ['id'];
	protected $table = 'metrics';
	public $timestamps = false;
}
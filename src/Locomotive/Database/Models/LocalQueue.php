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
use Illuminate\Database\Eloquent\SoftDeletes;

class LocalQueue extends Model
{
	use SoftDeletes;

	protected $guarded = ['id'];
	protected $table = 'queue';

	public function scopeLftpActive($query, $mappedKeys)
	{
		return $query->whereNotIn('id', $mappedKeys);
	}

	public function scopeFinished($query)
	{
		return $query->where('is_finished', true);
	}

	public function scopeNotFinished($query)
	{
		return $query->where('is_finished', false);
	}

	public function scopeMoved($query)
	{
		return $query->where('is_moved', true);
	}

	public function scopeNotMoved($query)
	{
		return $query->where('is_moved', false);
	}

	public function scopeFailed($query)
	{
		return $query->where('is_failed', true);
	}

	public function scopeNotFailed($query)
	{
		return $query->where('is_failed', false);
	}

	public function scopeForRun($query, $runId)
	{
		return $query->where('run_id', $runId);
	}

	public function scopeNotForRun($query, $runId)
	{
		return $query->where('run_id', '!=', $runId);
	}
}
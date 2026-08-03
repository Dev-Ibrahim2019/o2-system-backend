<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IdempotencyRecord extends Model { protected $fillable=['user_id','scope','key','request_hash','status','resource_type','resource_id','response','response_status']; protected $casts=['response'=>'array']; }

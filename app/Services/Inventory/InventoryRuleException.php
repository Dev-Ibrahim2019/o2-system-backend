<?php

namespace App\Services\Inventory;

use RuntimeException;

/** مخالفة قاعدة عمل في وحدة المخازن — رسالتها عربية وتُعرض للمستخدم كما هي (422). */
class InventoryRuleException extends RuntimeException
{
    /** @param  array<string, array<string>>  $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

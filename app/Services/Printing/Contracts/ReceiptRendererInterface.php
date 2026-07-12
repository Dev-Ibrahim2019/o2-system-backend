<?php

namespace App\Services\Printing\Contracts;

interface ReceiptRendererInterface
{
    /**
     * Render receipt content as ESC/POS-compatible text.
     */
    public function render(array $data): string;
}

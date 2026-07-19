<?php
namespace App\Services\Support;
class PhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $digits=preg_replace('/\D+/', '', $phone) ?? '';
        if(str_starts_with($digits,'00970'))$digits=substr($digits,2);
        elseif(str_starts_with($digits,'0'))$digits='970'.substr($digits,1);
        elseif(!str_starts_with($digits,'970'))$digits='970'.$digits;
        return '+'.$digits;
    }
}

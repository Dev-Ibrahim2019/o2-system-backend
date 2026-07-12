<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/print-preview', function () {
    return view('receipts/preview'); // هنا نكتب اسم ملف المعاينة بدون .blade.php
});
